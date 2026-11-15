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
use App\Services\Transfers;
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

    public function test_move_to_other_branch_goes_to_draft_act_and_to_1c_on_issue(): void
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
        $this->assertTrue($act->isDraft());
        $this->assertSame('П-'.today()->year.'-00001', $act->number);
        $this->assertSame($karaganda->floor->building->office_id, $act->from_office_id);
        $this->assertSame(['INV-1'], $act->items()->pluck('inventory_number')->all());
        $this->assertSame(0, OutboxMessage::count());

        app(Transfers::class)->issue($act);
        $msg = OutboxMessage::sole();
        $this->assertSame('onec', $msg->target);
        $this->assertSame('INV-1', $msg->payload['lines'][0]['inventory_number']);
        $this->assertSame('pending', $msg->status);

        $this->expectException(ValidationException::class);
        app(Transfers::class)->issue($act->fresh());
    }

    public function test_several_assets_one_act_per_direction(): void
    {
        $karaganda = $this->room(1);
        $astana = $this->room(2);
        $molA = Employee::create(['hr_id' => 11, 'full_name' => 'МОЛ А']);
        $molB = Employee::create(['hr_id' => 12, 'full_name' => 'МОЛ Б']);
        $first = $this->asset($karaganda, attrs: ['mol_id' => $molA->id]);
        $second = Asset::create($first->only(['category_id', 'office_id', 'room_id', 'floor_id', 'mol_id']) + ['name' => 'Центрифуга', 'inventory_number' => 'INV-2']);
        $third = Asset::create($first->only(['category_id', 'office_id', 'room_id', 'floor_id']) + ['name' => 'Микроскоп', 'inventory_number' => 'INV-3', 'mol_id' => $molB->id]);

        $acts = app(AssetMover::class)->moveMany([$first, $second, $third], ['room_id' => $astana->id], 'Открытие лаборатории');

        // Два МОЛ-отправителя – два акта, как два документа в 1С.
        $this->assertCount(2, $acts);
        $this->assertSame(2, AssetTransfer::count());
        $this->assertEqualsCanonicalizing(['INV-1', 'INV-2'], AssetTransfer::where('from_mol_id', $molA->id)->sole()->items()->pluck('inventory_number')->all());

        // Еще одна единица того же направления позже – в тот же черновик.
        $fourth = Asset::create($first->only(['category_id']) + ['office_id' => $karaganda->floor->building->office_id, 'room_id' => $karaganda->id, 'mol_id' => $molA->id, 'name' => 'Дозатор', 'inventory_number' => 'INV-4']);
        app(AssetMover::class)->move($fourth, ['room_id' => $astana->id]);
        $this->assertSame(2, AssetTransfer::count());
        $this->assertSame(3, AssetTransfer::where('from_mol_id', $molA->id)->sole()->items()->count());

        // После оформления новое перемещение – уже новый акт.
        app(Transfers::class)->issue(AssetTransfer::where('from_mol_id', $molA->id)->sole());
        $this->assertCount(3, OutboxMessage::sole()->payload['lines']);
        app(AssetMover::class)->move($fourth->fresh(), ['room_id' => $karaganda->id]);
        app(AssetMover::class)->move($fourth->fresh(), ['room_id' => $astana->id]);
        $this->assertSame(4, AssetTransfer::count());
    }

    public function test_move_many_can_give_each_asset_its_own_place(): void
    {
        $room = $this->room();
        $next = Room::create(['floor_id' => $room->floor_id, 'number' => '102', 'name' => 'Процедурная',
            'polygon' => [[6, 0], [12, 0], [12, 4], [6, 4]]]);
        $first = $this->asset($room);
        $second = Asset::create($first->only(['category_id', 'office_id', 'room_id', 'floor_id']) + ['name' => 'Центрифуга', 'inventory_number' => 'INV-2', 'x' => 2, 'y' => 3]);

        // Выделили на плане и перетащили: сдвиг один, точка у каждой своя.
        app(AssetMover::class)->moveMany([$first, $second], fn (Asset $a) => ['room_id' => $next->id, 'x' => $a->x + 6, 'y' => $a->y]);

        $this->assertSame([7.0, 1.0], [$first->fresh()->x, $first->fresh()->y]);
        $this->assertSame([8.0, 3.0], [$second->fresh()->x, $second->fresh()->y]);
        $this->assertSame([$next->id, $next->id], [$first->fresh()->room_id, $second->fresh()->room_id]);
        $this->assertSame([1, 1], [$first->moves()->count(), $second->moves()->count()]);
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

    public function test_it_device_goes_to_it_at_once_event_from_it_only_to_act(): void
    {
        $karaganda = $this->room(1);
        $astana = $this->room(2);
        $asset = $this->asset($karaganda, it: true, attrs: ['it_device_id' => 77]);

        $move = app(AssetMover::class)->move($asset, ['room_id' => $astana->id]);
        $toIt = OutboxMessage::sole();
        $this->assertSame('it', $toIt->target);
        $this->assertSame(77, $toIt->payload['it_device_id']);
        $this->assertSame(AssetTransfer::sole()->number, $toIt->payload['transfer_number']);
        $this->assertTrue($toIt->subject->is($move));

        // Обратно – перемещение, сделанное в it.his.kz: свой черновик акта, в it не отправляем.
        $mover = app(AssetMover::class);
        $back = $mover->move($asset->fresh(), ['room_id' => $karaganda->id], 'из it.his.kz', itMoveId: 501);
        $this->assertSame('it', $back->origin);
        $this->assertSame('it', AssetTransfer::latest('id')->first()->origin);
        $this->assertSame(2, AssetTransfer::count());
        $this->assertSame(1, OutboxMessage::where('target', 'it')->count());

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
