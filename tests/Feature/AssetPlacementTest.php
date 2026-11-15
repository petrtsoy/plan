<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Layer;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Поворот единицы на плане и перенос выделенных разом (AssetMover::moveMany). */
class AssetPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotation_is_kept_normalized_and_is_not_a_move(): void
    {
        $room = $this->room();
        $asset = $this->asset($room, 'INV-1');
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->postJson("/assets/{$asset->id}/place", ['floor_id' => $room->floor_id, 'x' => 2, 'y' => 2, 'rotation' => 390])
            ->assertOk()->assertJsonPath('message', null);
        $this->assertSame(30.0, $asset->fresh()->rotation);
        $this->assertSame(0, $asset->moves()->count());

        $this->postJson("/assets/{$asset->id}/place", ['floor_id' => $room->floor_id, 'x' => 2, 'y' => 2, 'rotation' => -90])
            ->assertOk();
        $this->assertSame(270.0, $asset->fresh()->rotation);
    }

    public function test_turning_in_place_keeps_the_room_even_without_contours(): void
    {
        // Этаж после импорта проекта: помещения из экспликации еще без контуров.
        $room = $this->room();
        $room->update(['polygon' => null]);
        $asset = $this->asset($room, 'INV-1', 3, 2);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->postJson("/assets/{$asset->id}/place", ['floor_id' => $room->floor_id, 'x' => 3, 'y' => 2, 'rotation' => 45])->assertOk();
        $this->postJson("/floors/{$room->floor_id}/assets/place-many", ['ids' => [$asset->id], 'dx' => 0, 'dy' => 0, 'rotation' => 180])->assertOk();

        $this->assertSame($room->id, $asset->fresh()->room_id, 'поворот – не перестановка, помещение остается');
        $this->assertSame(180.0, $asset->fresh()->rotation);
        $this->assertSame(0, $asset->moves()->count());
    }

    public function test_selected_assets_move_together_keeping_layout(): void
    {
        $room = $this->room();
        $next = Room::create(['floor_id' => $room->floor_id, 'number' => '102', 'name' => 'Процедурная',
            'polygon' => [[6, 0], [12, 0], [12, 4], [0 + 6, 4]]]);
        $first = $this->asset($room, 'INV-1', 1, 1);
        $second = $this->asset($room, 'INV-2', 2, 3);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->postJson("/floors/{$room->floor_id}/assets/place-many",
            ['ids' => [$first->id, $second->id], 'dx' => 6, 'dy' => 0, 'rotation' => 90])
            ->assertOk()->assertJsonPath('message', 'Перенесено единиц: 2.');

        // Сдвинулись на один вектор, взаимное расположение то же, помещение – новое.
        $this->assertSame([7.0, 1.0], [$first->fresh()->x, $first->fresh()->y]);
        $this->assertSame([8.0, 3.0], [$second->fresh()->x, $second->fresh()->y]);
        $this->assertSame([$next->id, $next->id], [$first->fresh()->room_id, $second->fresh()->room_id]);
        $this->assertSame(90.0, $first->fresh()->rotation);
        $this->assertSame(1, $first->moves()->count());
        $this->assertSame('Перенос группой на плане', $second->moves()->sole()->comment);

        // Вне помещений – тоже место: помещение просто снимается.
        $this->postJson("/floors/{$room->floor_id}/assets/place-many", ['ids' => [$first->id], 'dx' => 0, 'dy' => 20])->assertOk();
        $this->assertNull($first->fresh()->room_id);

        $this->postJson("/floors/{$room->floor_id}/assets/place-many", ['ids' => [], 'dx' => 1, 'dy' => 1])->assertJsonValidationErrors('ids');
    }

    public function test_viewer_does_not_move_anything(): void
    {
        $room = $this->room();
        $asset = $this->asset($room, 'INV-1');
        $this->actingAs(User::factory()->role(Role::Viewer)->create());

        $this->postJson("/floors/{$room->floor_id}/assets/place-many", ['ids' => [$asset->id], 'dx' => 1, 'dy' => 1])->assertForbidden();
        $this->get("/floors/{$room->floor_id}")->assertOk()->assertDontSee('data-tool="select"', false);
    }

    private function asset(Room $room, string $inventory, float $x = 1, float $y = 1): Asset
    {
        $layer = Layer::firstOrCreate(['code' => 'med'], ['name' => 'Медоборудование']);
        $category = Category::firstOrCreate(['layer_id' => $layer->id, 'name' => 'УЗИ']);

        return Asset::create(['category_id' => $category->id, 'name' => 'УЗИ '.$inventory, 'inventory_number' => $inventory,
            'office_id' => $room->floor->building->office_id, 'room_id' => $room->id, 'floor_id' => $room->floor_id, 'x' => $x, 'y' => $y]);
    }
}
