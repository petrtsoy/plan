<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Layer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FloorPlanTest extends TestCase
{
    use RefreshDatabase;

    private function category(): Category
    {
        return Category::create(['layer_id' => Layer::create(['code' => 'med', 'name' => 'Медоборудование'])->id, 'name' => 'УЗИ']);
    }

    public function test_pages_open_for_viewer_but_editing_is_forbidden(): void
    {
        $room = $this->room();
        $floor = $room->floor;
        $this->actingAs(User::factory()->role(Role::Viewer)->create());

        $this->get('/buildings')->assertOk()->assertSee('Корпус');
        $this->get("/buildings/{$floor->building_id}")->assertOk()->assertSee('1 этаж');
        $this->get("/floors/{$floor->id}")->assertOk()->assertSee('Чертежа еще нет')->assertDontSee('data-tool="room"', false);

        $this->postJson("/floors/{$floor->id}/rooms", ['name' => 'X', 'polygon' => [[0, 0], [1, 0], [1, 1]]])->assertForbidden();
        $this->post("/buildings/{$floor->building_id}/floors", ['level' => 2, 'name' => '2 этаж'])->assertForbidden();
    }

    public function test_upload_image_and_first_calibration_rescales_floor(): void
    {
        Storage::fake('local');
        $room = $this->room();
        $floor = $room->floor;
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        // До масштаба координаты – пиксели картинки: помещение 600 x 400 px, единица в точке (150, 100).
        $room->update(['polygon' => [[0, 0], [600, 0], [600, 400], [0, 400]]]);
        $asset = Asset::create(['category_id' => $this->category()->id, 'name' => 'УЗИ', 'office_id' => $floor->building->office_id,
            'floor_id' => $floor->id, 'room_id' => $room->id, 'x' => 150, 'y' => 100]);

        $this->post("/floors/{$floor->id}/plans", ['file' => UploadedFile::fake()->image('этаж.png', 1200, 800)])->assertRedirect();
        $plan = $floor->fresh()->plan;
        $this->assertSame([1200, 800], [$plan->width_px, $plan->height_px]);
        $this->assertFalse($plan->isCalibrated());
        Storage::disk('local')->assertExists($plan->image_path);
        $this->get("/plans/{$plan->id}/image")->assertOk();

        // Отрезок 600 px – это 6 м: 1 px = 1 см.
        $this->postJson("/plans/{$plan->id}/calibrate", ['p1' => [0, 0], 'p2' => [600, 0], 'meters' => 6])->assertOk();

        $this->assertEqualsWithDelta(0.01, $plan->fresh()->meters_per_px, 1e-9);
        $this->assertEquals([[0, 0], [6, 0], [6, 4], [0, 4]], $room->fresh()->polygon);
        $this->assertEqualsWithDelta(24, (float) $room->fresh()->area_m2, 0.001);
        $this->assertEquals([1.5, 1.0], [$asset->fresh()->x, $asset->fresh()->y]);

        // Поправили масштаб той же подложки: расставленное остается на своих местах картинки.
        $this->postJson("/plans/{$plan->id}/calibrate", ['p1' => [0, 0], 'p2' => [6, 0], 'meters' => 6.2])->assertOk();
        $this->assertEquals([1.55, 1.033], [$asset->fresh()->x, $asset->fresh()->y]);

        // Замена подложки: метры остаются, новая картинка той же ширины в метрах.
        $this->post("/floors/{$floor->id}/plans", ['file' => UploadedFile::fake()->image('новый.jpg', 2400, 1600)])->assertRedirect();
        $new = $floor->fresh()->plan;
        $this->assertNotEquals($plan->id, $new->id);
        $this->assertEqualsWithDelta(1200 * $plan->fresh()->meters_per_px, 2400 * $new->meters_per_px, 1e-6);
        $this->assertFalse($plan->fresh()->is_current);
    }

    public function test_cad_files_are_refused_for_now(): void
    {
        Storage::fake('local');
        $floor = $this->room()->floor;
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->post("/floors/{$floor->id}/plans", ['file' => UploadedFile::fake()->create('этаж.dwg', 10)])->assertSessionHasErrors('file');
    }

    public function test_room_drawn_and_asset_placed_by_contour(): void
    {
        $room = $this->room();
        $floor = $room->floor;
        $category = $this->category();
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $res = $this->postJson("/floors/{$floor->id}/rooms", ['number' => '102', 'name' => 'Процедурная',
            'polygon' => [[6, 0], [10, 0], [10, 4], [6, 4]]])->assertOk();
        $this->assertEquals(16, $res->json('area'));
        $other = $floor->rooms()->where('number', '102')->first();

        // Новая единица прямо с плана – в помещение по контуру.
        $res = $this->postJson("/floors/{$floor->id}/assets", ['category_id' => $category->id, 'name' => 'УЗИ', 'inventory_number' => 'INV-1', 'x' => 2, 'y' => 2])->assertOk();
        $asset = Asset::where('inventory_number', 'INV-1')->first();
        $this->assertSame($room->id, $asset->room_id);
        $this->assertSame($floor->building->office_id, $asset->office_id);
        $this->assertSame(1, $asset->moves()->count());

        // Перетащили в соседнее помещение – перемещение с историей.
        $this->postJson("/assets/{$asset->id}/place", ['floor_id' => $floor->id, 'x' => 8, 'y' => 1])->assertOk()->assertJsonPath('room', '102 Процедурная');
        $this->assertSame($other->id, $asset->fresh()->room_id);
        $this->assertSame(2, $asset->moves()->count());

        // Внутри помещения – только точка, без записи.
        $this->postJson("/assets/{$asset->id}/place", ['floor_id' => $floor->id, 'x' => 9, 'y' => 3])->assertOk()->assertJsonPath('message', null);
        $this->assertSame(2, $asset->moves()->count());

        $this->get("/floors/{$floor->id}")->assertOk()
            ->assertViewHas('map', fn ($map) => $map['rooms']->pluck('title')->all() === ['101 Лаборатория', '102 Процедурная']
                && $map['assets']->first()['x'] === 9.0);
        $this->get("/assets/{$asset->id}")->assertOk()->assertSee('INV-1')->assertSee('Процедурная');
    }
}
