<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Asset;
use App\Models\AssetMove;
use App\Models\Category;
use App\Models\Layer;
use App\Models\Room;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Убрать с плана: помещение, зону и расставленные единицы. */
class RoomDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function asset(Room $room): Asset
    {
        $layer = Layer::create(['code' => 'lab', 'name' => 'Слой']);
        $category = Category::create(['layer_id' => $layer->id, 'name' => 'Анализаторы']);

        return Asset::create([
            'category_id' => $category->id, 'name' => 'Анализатор', 'inventory_number' => 'INV-1',
            'office_id' => $room->floor->building->office_id, 'room_id' => $room->id,
            'floor_id' => $room->floor_id, 'x' => 2, 'y' => 2,
        ]);
    }

    public function test_room_with_assets_stays_and_empty_one_goes_with_history_kept(): void
    {
        $room = $this->room();
        $asset = $this->asset($room);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->deleteJson(route('rooms.destroy', $room))->assertStatus(422);
        $this->assertNotNull($room->fresh());

        // Сняли единицу с плана – и помещение убирается.
        $this->postJson(route('floors.assets.unplace', $room->floor), ['ids' => [$asset->id]])->assertOk();
        $asset->refresh();
        $this->assertSame([null, null, null, null], [$asset->floor_id, $asset->room_id, $asset->x, $asset->y]);
        $this->assertSame($room->id, AssetMove::where('asset_id', $asset->id)->value('from_room_id'));

        $this->deleteJson(route('rooms.destroy', $room))->assertOk();
        $this->assertNull(Room::find($room->id));
        $this->assertSame(0, $room->floor->rooms()->count());
        // История помнит, откуда уехала единица, хотя помещения на плане уже нет.
        $this->assertSame($room->title, AssetMove::where('asset_id', $asset->id)->first()->fromRoom->title);
    }

    public function test_zone_goes_off_the_plan(): void
    {
        $floor = $this->room()->floor;
        $zone = Zone::create(['floor_id' => $floor->id, 'name' => 'Чистая зона', 'kind' => 'clean',
            'polygon' => [[0, 0], [4, 0], [4, 4], [0, 4]]]);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->deleteJson(route('zones.destroy', $zone))->assertOk();
        $this->assertSame(0, $floor->zones()->count());
    }
}
