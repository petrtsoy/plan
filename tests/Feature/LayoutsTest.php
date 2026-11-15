<?php

namespace Tests\Feature;

use App\Enums\LayoutStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\AssetMove;
use App\Models\Category;
use App\Models\Layer;
use App\Models\Layout;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Примерка: справочник моделей, позиции варианта, проверка влезания и утверждение. */
class LayoutsTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private AssetModel $model;

    private Layout $layout;

    protected function setUp(): void
    {
        parent::setUp();

        // Помещение 6 x 4 м с заземлением и сливом; анализатор 1 x 0,6 м с зоной 0,5 м спереди.
        $this->room = $this->room(1, ['grounding' => true, 'drain' => true, 'power_kw' => 10]);
        $category = Category::create(['layer_id' => Layer::create(['code' => 'lab', 'name' => 'Лаборатория'])->id, 'name' => 'Анализаторы']);
        $this->model = AssetModel::create([
            'category_id' => $category->id, 'manufacturer' => 'Demo', 'name' => 'Анализатор',
            'width_mm' => 1000, 'depth_mm' => 600, 'clearance' => ['front' => 500],
            'requirements' => ['grounding' => true, 'drain' => true, 'power_kw' => 3],
        ]);
        $this->layout = Layout::create(['floor_id' => $this->room->floor_id, 'name' => 'Вариант 1']);
    }

    private function engineer(): User
    {
        return User::factory()->role(Role::Engineer)->create();
    }

    private function place(array $data): array
    {
        return $this->actingAs($this->engineer())
            ->postJson(route('layouts.items.store', $this->layout), $data + ['model_id' => $this->model->id])
            ->assertOk()->json('items');
    }

    public function test_item_inside_room_fits_and_gets_its_room(): void
    {
        $items = $this->place(['x' => 2, 'y' => 2]);

        $this->assertCount(1, $items);
        $this->assertSame($this->room->id, $items[0]['roomId']);
        $this->assertSame('101 Лаборатория', $items[0]['room']);
        $this->assertTrue($items[0]['fit']['ok'], implode(' / ', $items[0]['fit']['problems']));
        // След: 1 м по x, по y – 0,3 назад и 0,3 + 0,5 сервисной зоны вперед (перед смотрит вниз).
        $this->assertSame([[1.5, 1.7], [2.5, 1.7], [2.5, 2.8], [1.5, 2.8]], $items[0]['footprint']);
    }

    public function test_item_sticking_out_of_the_room_does_not_fit(): void
    {
        $items = $this->place(['x' => 5.8, 'y' => 2]);

        $this->assertFalse($items[0]['fit']['ok']);
        $this->assertContains('Не влезает в помещение', $items[0]['fit']['problems']);
    }

    public function test_service_zone_outside_the_room_is_reported_separately(): void
    {
        // Сам аппарат (0,6 м по y) в помещении, а зона 0,5 м спереди – уже за стеной.
        $items = $this->place(['x' => 3, 'y' => 3.6]);

        $this->assertFalse($items[0]['fit']['ok']);
        $this->assertContains('Сервисная зона выходит за помещение', $items[0]['fit']['problems']);
    }

    public function test_point_outside_any_room_is_reported(): void
    {
        $items = $this->place(['x' => 20, 'y' => 20]);

        $this->assertNull($items[0]['roomId']);
        $this->assertContains('Вне помещений', $items[0]['fit']['problems']);
    }

    public function test_service_zones_of_two_items_must_not_overlap(): void
    {
        $this->place(['x' => 1.5, 'y' => 2]);
        $items = $this->place(['x' => 2.2, 'y' => 2]);

        $second = collect($items)->last();
        $this->assertFalse($second['fit']['ok']);
        $this->assertSame(['Demo Анализатор'], $second['overlaps'] ?? $second['fit']['overlaps']);
        $this->assertContains('Пересекается с: Demo Анализатор', $second['fit']['problems']);

        // Отодвинули – и влезает: проверка считается заново при каждой правке.
        $id = $second['id'];
        $items = $this->actingAs($this->engineer())
            ->putJson(route('layout-items.update', ['item' => $id]), ['x' => 4.2, 'y' => 2])
            ->assertOk()->json('items');
        $this->assertTrue(collect($items)->firstWhere('id', $id)['fit']['ok']);
    }

    public function test_room_without_the_required_specs_is_reported(): void
    {
        $this->room->update(['specs' => ['grounding' => true, 'power_kw' => 1]]);

        $items = $this->place(['x' => 2, 'y' => 2]);

        $this->assertSame([
            'Слив в канализацию',
            'Мощность: нужно 3 кВт, есть 1',
        ], $items[0]['fit']['missing']);
        $this->assertFalse($items[0]['fit']['ok']);
    }

    public function test_rotation_turns_the_footprint_with_its_service_zone(): void
    {
        // Поворот на 90°: метровая сторона встает по y, а сервисная зона смотрит уже влево.
        $items = $this->place(['x' => 3, 'y' => 2, 'rotation' => 90]);

        $this->assertSame([[3.3, 1.5], [3.3, 2.5], [2.2, 2.5], [2.2, 1.5]], $items[0]['footprint']);
        $this->assertTrue($items[0]['fit']['ok'], implode(' / ', $items[0]['fit']['problems']));

        // У левой стены повернутому аппарату уже не хватает места на обслуживание.
        $items = $this->place(['x' => 0.6, 'y' => 2, 'rotation' => 90]);
        $turned = collect($items)->last();
        $this->assertFalse($turned['fit']['ok']);
        $this->assertContains('Сервисная зона выходит за помещение', $turned['fit']['problems']);
    }

    public function test_what_already_stands_on_the_floor_is_an_obstacle(): void
    {
        Asset::create([
            'category_id' => $this->model->category_id, 'model_id' => $this->model->id,
            'name' => 'Старый анализатор', 'office_id' => $this->room->floor->building->office_id,
            'floor_id' => $this->room->floor_id, 'room_id' => $this->room->id, 'x' => 1.5, 'y' => 2,
        ]);

        $items = $this->place(['x' => 2.2, 'y' => 2]);

        $this->assertContains('Старый анализатор (стоит)', $items[0]['fit']['overlaps']);
    }

    public function test_item_without_dimensions_is_placed_but_not_checked(): void
    {
        $bare = AssetModel::create(['category_id' => $this->model->category_id, 'name' => 'Без габаритов']);
        $items = $this->actingAs($this->engineer())
            ->postJson(route('layouts.items.store', $this->layout), ['model_id' => $bare->id, 'x' => 2, 'y' => 2])
            ->assertOk()->json('items');

        $this->assertNull($items[0]['shape']);
        $this->assertContains('Габариты не заданы – место не проверить.', $items[0]['fit']['notes']);
        $this->assertTrue($items[0]['fit']['ok']);
    }

    public function test_approve_moves_existing_asset_and_creates_new_one_with_layout_in_the_move(): void
    {
        $asset = Asset::create([
            'category_id' => $this->model->category_id, 'model_id' => $this->model->id,
            'name' => 'Переставляемый', 'office_id' => $this->room->floor->building->office_id,
        ]);
        $this->actingAs($this->engineer())
            ->postJson(route('layouts.items.store', $this->layout), ['asset_id' => $asset->id, 'x' => 1.5, 'y' => 2])
            ->assertOk();
        $this->place(['x' => 4.2, 'y' => 2]);

        $this->actingAs(User::factory()->role(Role::Engineer)->create())
            ->post(route('layouts.approve', $this->layout))
            ->assertRedirect(route('layouts.show', $this->layout));

        // Существующая единица встала на место из варианта.
        $asset->refresh();
        $this->assertSame($this->room->id, $asset->room_id);
        $this->assertSame($this->room->floor_id, $asset->floor_id);
        $this->assertSame([1.5, 2.0], [$asset->x, $asset->y]);

        // Модель из справочника завелась новой единицей филиала и тоже встала на место.
        $created = Asset::where('name', 'Анализатор')->sole();
        $this->assertSame($this->model->id, $created->model_id);
        $this->assertSame($this->room->id, $created->room_id);
        $this->assertSame($this->room->floor->building->office_id, $created->office_id);

        // В каждом перемещении осталась ссылка на вариант.
        $moves = AssetMove::whereIn('asset_id', [$asset->id, $created->id])->get();
        $this->assertCount(2, $moves);
        $this->assertSame([$this->layout->id, $this->layout->id], $moves->pluck('layout_id')->all());
        $this->assertSame(['Утвержден вариант "Вариант 1"'], $moves->pluck('comment')->unique()->all());

        $this->layout->refresh();
        $this->assertSame(LayoutStatus::Approved, $this->layout->status);
        $this->assertNotNull($this->layout->approved_at);

        // Утвержденный вариант больше не правится и не утверждается второй раз.
        $this->actingAs($this->engineer())
            ->postJson(route('layouts.items.store', $this->layout), ['model_id' => $this->model->id, 'x' => 3, 'y' => 1])
            ->assertStatus(422);
        $this->actingAs($this->engineer())->post(route('layouts.approve', $this->layout))
            ->assertRedirect()->assertSessionHasErrors('layout');
        $this->assertSame(1, Asset::where('name', 'Анализатор')->count());
    }

    public function test_variants_are_compared_on_the_floor_screen(): void
    {
        $this->place(['x' => 2, 'y' => 2]);
        $this->place(['x' => 20, 'y' => 20]);

        $this->actingAs($this->engineer())->get(route('floors.layouts.index', $this->room->floor))
            ->assertOk()
            ->assertSee('Вариант 1')
            ->assertSee('1 из 2')
            ->assertSee('Вне помещений');
    }

    public function test_layout_screen_shows_items_and_models(): void
    {
        $this->place(['x' => 2, 'y' => 2]);

        $this->actingAs($this->engineer())->get(route('layouts.show', $this->layout))
            ->assertOk()
            ->assertSee('Demo Анализатор')
            ->assertSee('Поставить');
    }

    public function test_viewer_cannot_change_or_approve_a_variant(): void
    {
        $viewer = User::factory()->role(Role::Viewer)->create();

        $this->actingAs($viewer)->get(route('layouts.show', $this->layout))->assertOk();
        $this->actingAs($viewer)
            ->postJson(route('layouts.items.store', $this->layout), ['model_id' => $this->model->id, 'x' => 2, 'y' => 2])
            ->assertForbidden();
        $this->actingAs($viewer)->post(route('layouts.approve', $this->layout))->assertForbidden();
    }

    public function test_models_catalog_screens(): void
    {
        $engineer = $this->engineer();

        $this->actingAs($engineer)->get(route('models.index'))->assertOk()->assertSee('Demo Анализатор');

        $this->actingAs($engineer)->post(route('models.store'), [
            'category_id' => $this->model->category_id,
            'manufacturer' => 'Sysmex',
            'name' => 'XN-1000',
            'width_mm' => 600, 'depth_mm' => 550, 'height_mm' => 500,
            'clearance' => ['front' => 600, 'left' => '', 'right' => 300],
            'requirements' => ['grounding' => '1', 'drain' => '1', 'power_kw' => '2,5', 'ups' => '0', 'clean_class' => ''],
            'intervals' => ['service' => 6, 'verification' => ''],
        ])->assertRedirect();

        $new = AssetModel::where('name', 'XN-1000')->sole();
        $this->assertSame(['front' => 600, 'right' => 300], $new->clearance);
        $this->assertSame(['power_kw' => 2.5, 'grounding' => true, 'drain' => true], $new->requirements);
        $this->assertSame(['service' => 6], $new->intervals);

        $this->actingAs($engineer)->get(route('models.edit', $new))->assertOk()->assertSee('XN-1000');

        // Просмотр справочник не правит.
        $this->actingAs(User::factory()->role(Role::Viewer)->create())->get(route('models.create'))->assertForbidden();
    }
}
