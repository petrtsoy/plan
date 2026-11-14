<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Layer;
use App\Models\OutboxMessage;
use App\Models\Room;
use App\Services\AssetMover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssetMoverTest extends TestCase
{
    use RefreshDatabase;

    private function asset(Room $room, bool $it = false, array $attrs = []): Asset
    {
        $layer = Layer::create(['code' => $it ? 'it' : 'lab', 'name' => 'Слой']);
        $category = Category::create(['layer_id' => $layer->id, 'name' => $it ? 'Компьютеры' : 'Анализаторы', 'is_it' => $it]);

        return Asset::create($attrs + [
            'category_id' => $category->id,
            'name' => 'Анализатор',
            'inventory_number' => 'INV-1',
            'office_id' => $room->floor->building->office_id,
            'room_id' => $room->id,
            'floor_id' => $room->floor_id,
            'x' => 1, 'y' => 1,
        ]);
    }

    public function test_point_change_inside_room_is_not_a_move(): void
    {
        $room = $this->room();
        $asset = $this->asset($room);

        $this->assertNull(app(AssetMover::class)->move($asset, ['x' => 3, 'y' => 2]));
        $this->assertSame(3.0, $asset->fresh()->x);
        $this->assertSame(0, $asset->moves()->count());
    }

    public function test_move_to_other_branch_creates_transfer_act_and_1c_message(): void
    {
        $karaganda = $this->room(1);
        $astana = $this->room(2);
        $asset = $this->asset($karaganda);

        $move = app(AssetMover::class)->move($asset, ['room_id' => $astana->id, 'x' => 2, 'y' => 2], 'Перераспределение');

        $asset->refresh();
        $this->assertSame($astana->floor->building->office_id, $asset->office_id);
        $this->assertSame($astana->floor_id, $asset->floor_id);
        $this->assertSame('plan', $move->origin);

        $act = AssetTransfer::sole();
        $this->assertSame('П-'.today()->year.'-00001', $act->number);
        $this->assertSame($karaganda->floor->building->office_id, $act->from_office_id);

        $msg = OutboxMessage::sole();
        $this->assertSame('onec', $msg->target);
        $this->assertSame('INV-1', $msg->payload['inventory_number']);
        $this->assertSame('pending', $msg->status);
    }

    public function test_move_inside_branch_without_mol_change_has_no_act(): void
    {
        $room = $this->room();
        $other = Room::create(['floor_id' => $room->floor_id, 'number' => '102', 'name' => 'Склад']);
        $asset = $this->asset($room);

        $this->assertNotNull(app(AssetMover::class)->move($asset, ['room_id' => $other->id]));
        $this->assertSame(0, AssetTransfer::count());
    }

    public function test_mol_change_is_a_transfer(): void
    {
        $room = $this->room();
        $mol = Employee::create(['hr_id' => 10, 'full_name' => 'Петров П.П.']);
        $asset = $this->asset($room);

        app(AssetMover::class)->move($asset, ['mol_id' => $mol->id]);
        $this->assertSame($mol->id, AssetTransfer::sole()->to_mol_id);
    }

    public function test_it_device_goes_to_it_and_1c_but_event_from_it_only_to_1c(): void
    {
        $karaganda = $this->room(1);
        $astana = $this->room(2);
        $asset = $this->asset($karaganda, it: true, attrs: ['it_device_id' => 77]);

        app(AssetMover::class)->move($asset, ['room_id' => $astana->id]);
        $this->assertEqualsCanonicalizing(['onec', 'it'], OutboxMessage::pluck('target')->all());
        $this->assertSame(77, OutboxMessage::where('target', 'it')->sole()->payload['it_device_id']);

        // Обратно – перемещение, сделанное в it.his.kz: акт и 1С, но в it не отправляем.
        $mover = app(AssetMover::class);
        $move = $mover->move($asset->fresh(), ['room_id' => $karaganda->id], 'из it.his.kz', itMoveId: 501);
        $this->assertSame('it', $move->origin);
        $this->assertSame(2, AssetTransfer::count());
        $this->assertSame(1, OutboxMessage::where('target', 'it')->count());
        $this->assertSame(2, OutboxMessage::where('target', 'onec')->count());

        // Повторная доставка того же события – ничего.
        $this->assertNull($mover->move($asset->fresh(), ['room_id' => $astana->id], itMoveId: 501));
        $this->assertSame(2, AssetTransfer::count());
    }

    public function test_room_must_belong_to_given_branch(): void
    {
        $karaganda = $this->room(1);
        $astana = $this->room(2);
        $asset = $this->asset($karaganda);

        $this->expectException(ValidationException::class);
        app(AssetMover::class)->move($asset, ['office_id' => $karaganda->floor->building->office_id, 'room_id' => $astana->id]);
    }
}
