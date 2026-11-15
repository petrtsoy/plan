<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\InventoryMark;
use App\Enums\InventoryStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Inventory;
use App\Models\Layer;
use App\Models\Room;
use App\Models\User;
use App\Services\Inventories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function category(): Category
    {
        $layer = Layer::firstOrCreate(['code' => 'lab'], ['name' => 'Лаборатория']);

        return Category::firstOrCreate(['layer_id' => $layer->id, 'name' => 'Анализаторы']);
    }

    private function asset(Room $room, array $attrs = []): Asset
    {
        return Asset::create($attrs + [
            'category_id' => $this->category()->id,
            'name' => 'Анализатор',
            'office_id' => $room->floor->building->office_id,
            'room_id' => $room->id,
            'floor_id' => $room->floor_id,
            'x' => 1, 'y' => 1,
        ]);
    }

    private function second(Room $room, string $number = '102'): Room
    {
        return Room::create([
            'floor_id' => $room->floor_id,
            'number' => $number,
            'name' => 'Склад',
            'polygon' => [[6, 0], [12, 0], [12, 4], [6, 4]],
        ]);
    }

    public function test_open_takes_expected_room_and_mol_and_skips_written_off(): void
    {
        $room = $this->room();
        $store = $this->second($room);
        $mol = Employee::create(['hr_id' => 7, 'full_name' => 'Петров П.П.']);
        $counted = $this->asset($room, ['inventory_number' => 'INV-1', 'mol_id' => $mol->id]);
        $unplaced = Asset::create(['category_id' => $this->category()->id, 'name' => 'Дозатор', 'office_id' => $room->floor->building->office_id]);
        $this->asset($store, ['inventory_number' => 'INV-W', 'status' => AssetStatus::WrittenOff]);

        $inventory = app(Inventories::class)->open($room->floor->building->office, ['name' => 'Годовая']);

        $this->assertSame(InventoryStatus::Open, $inventory->status);
        $this->assertSame(2, $inventory->items()->count());
        $item = $inventory->items()->where('asset_id', $counted->id)->sole();
        $this->assertSame($room->id, $item->expected_room_id);
        $this->assertSame($mol->id, $item->expected_mol_id);
        $this->assertSame(InventoryMark::Pending, $item->mark);
        // Нерасставленное в ведомости есть, но без ожидаемого помещения.
        $this->assertNull($inventory->items()->where('asset_id', $unplaced->id)->sole()->expected_room_id);

        // Дальнейшее перемещение ожидаемое место в ведомости не меняет – иначе сверять нечего.
        $counted->update(['room_id' => $store->id]);
        $this->assertSame($room->id, $item->fresh()->expected_room_id);
    }

    public function test_open_by_rooms_only_and_one_inventory_per_office(): void
    {
        $room = $this->room();
        $store = $this->second($room);
        $this->asset($room, ['inventory_number' => 'INV-1']);
        $this->asset($store, ['inventory_number' => 'INV-2']);
        $office = $room->floor->building->office;

        $inventory = app(Inventories::class)->open($office, ['name' => 'Склад'], ['rooms' => [$store->id]]);
        $this->assertSame(['INV-2'], $inventory->items()->with('asset')->get()->pluck('asset.inventory_number')->all());
        $this->assertSame(['rooms' => [$store->id]], $inventory->scope);

        // Вторая открытая в том же филиале – некуда ставить отметку со скана QR.
        $this->expectException(ValidationException::class);
        app(Inventories::class)->open($office, ['name' => 'Еще одна']);
    }

    public function test_open_refuses_room_of_another_office(): void
    {
        $mine = $this->room(1);
        $other = $this->room(2);

        $this->expectException(ValidationException::class);
        app(Inventories::class)->open($mine->floor->building->office, ['name' => 'Чужое'], ['rooms' => [$other->id]]);
    }

    public function test_marks_found_misplaced_and_missing(): void
    {
        $room = $this->room();
        $store = $this->second($room);
        $inventories = app(Inventories::class);
        $onPlace = $this->asset($room, ['inventory_number' => 'INV-1']);
        $moved = $this->asset($room, ['inventory_number' => 'INV-2']);
        $lost = $this->asset($room, ['inventory_number' => 'INV-3']);
        $inventory = $inventories->open($room->floor->building->office, ['name' => 'Годовая']);
        $item = fn (Asset $asset) => $inventory->items()->where('asset_id', $asset->id)->sole();

        $inventories->mark($item($onPlace), InventoryMark::Found);
        $inventories->mark($item($moved), InventoryMark::Misplaced, $store->id);
        $inventories->mark($item($lost), InventoryMark::Missing, note: 'Искали всем отделением');

        $this->assertSame($room->id, $item($onPlace)->found_room_id);
        $this->assertNotNull($item($onPlace)->checked_at);
        $this->assertSame($store->id, $item($moved)->found_room_id);
        $this->assertNull($item($lost)->found_room_id);
        $this->assertSame('Искали всем отделением', $item($lost)->note);
        $this->assertSame(['pending' => 0, 'found' => 1, 'misplaced' => 1, 'missing' => 1, 'surplus' => 0, 'total' => 3], $inventories->counts($inventory));

        // "Не на своем месте" без помещения не бывает, а в своем же помещении – это "на месте".
        try {
            $inventories->mark($item($moved), InventoryMark::Misplaced);
            $this->fail('Ожидали отказ без помещения.');
        } catch (ValidationException) {
        }
        $inventories->mark($item($moved), InventoryMark::Misplaced, $room->id);
        $this->assertSame(InventoryMark::Found, $item($moved)->mark);
    }

    public function test_surplus_by_asset_and_by_description(): void
    {
        $room = $this->room(1);
        $store = $this->second($room);
        $elsewhere = $this->room(2);
        $inventories = app(Inventories::class);
        $stranger = $this->asset($elsewhere, ['inventory_number' => 'INV-9']);
        $inventory = $inventories->open($room->floor->building->office, ['name' => 'Годовая']);

        // Нашли то, что числится в другом филиале: ожидаемое место – где числится.
        $surplus = $inventories->addSurplus($inventory, $stranger, $store->id);
        $this->assertSame(InventoryMark::Surplus, $surplus->mark);
        $this->assertSame($elsewhere->id, $surplus->expected_room_id);
        $this->assertSame($store->id, $surplus->found_room_id);

        // Нашли то, чего нет в учете вовсе.
        $nameless = $inventories->addSurplus($inventory, null, $store->id, 'Холодильник Beko без номера');
        $this->assertNull($nameless->asset_id);

        // Излишек не переотмечают как строку ведомости, и без описания его не бывает.
        try {
            $inventories->mark($surplus, InventoryMark::Found);
            $this->fail('Ожидали отказ: это излишек.');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $inventories->addSurplus($inventory, null, $store->id);
    }

    public function test_surplus_refused_for_asset_already_in_sheet(): void
    {
        $room = $this->room();
        $store = $this->second($room);
        $asset = $this->asset($room, ['inventory_number' => 'INV-1']);
        $inventories = app(Inventories::class);
        $inventory = $inventories->open($room->floor->building->office, ['name' => 'Годовая']);

        $this->expectException(ValidationException::class);
        $inventories->addSurplus($inventory, $asset, $store->id);
    }

    public function test_close_moves_misplaced_through_asset_mover_and_locks_marks(): void
    {
        $room = $this->room();
        $store = $this->second($room);
        $inventories = app(Inventories::class);
        $moved = $this->asset($room, ['inventory_number' => 'INV-2']);
        $lost = $this->asset($room, ['inventory_number' => 'INV-3']);
        $inventory = $inventories->open($room->floor->building->office, ['name' => 'Годовая', 'order_number' => 'ПР-7']);

        $inventories->mark($inventory->items()->where('asset_id', $moved->id)->sole(), InventoryMark::Misplaced, $store->id);
        $inventories->mark($inventory->items()->where('asset_id', $lost->id)->sole(), InventoryMark::Missing);

        $this->assertSame(1, $inventories->close($inventory, 'Комиссия закончила'));

        $moved->refresh();
        $this->assertSame($store->id, $moved->room_id);
        $move = $moved->moves()->sole();
        $this->assertStringContainsString('Инвентаризация ПР-7', $move->comment);
        // Внутри филиала и без смены МОЛ акт передачи не нужен.
        $this->assertSame(0, AssetTransfer::count());
        // "Не найдено" место не меняет: решают по ведомости расхождений.
        $this->assertSame($room->id, $lost->fresh()->room_id);

        $inventory->refresh();
        $this->assertSame(InventoryStatus::Closed, $inventory->status);
        $this->assertNotNull($inventory->closed_at);
        $this->assertSame('Комиссия закончила', $inventory->notes);

        // Завершенную не отмечают и не закрывают второй раз.
        try {
            $inventories->mark($inventory->items()->where('asset_id', $lost->id)->sole(), InventoryMark::Found);
            $this->fail('Ожидали отказ: инвентаризация завершена.');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $inventories->close($inventory);
    }

    public function test_close_surplus_from_another_office_is_a_handover(): void
    {
        $room = $this->room(1);
        $elsewhere = $this->room(2);
        $inventories = app(Inventories::class);
        $stranger = $this->asset($elsewhere, ['inventory_number' => 'INV-9']);
        $inventory = $inventories->open($room->floor->building->office, ['name' => 'Годовая']);
        $inventories->addSurplus($inventory, $stranger, $room->id, 'Нашли у себя');

        $this->assertSame(1, $inventories->close($inventory));

        $stranger->refresh();
        $this->assertSame($room->id, $stranger->room_id);
        $this->assertSame($room->floor->building->office_id, $stranger->office_id);
        // Смена филиала – это передача: строка легла в черновик акта (Transfers).
        $this->assertSame(['INV-9'], AssetTransfer::sole()->items()->pluck('inventory_number')->all());
    }

    public function test_screens_and_marks_from_qr_card(): void
    {
        $room = $this->room();
        $store = $this->second($room);
        $asset = $this->asset($room, ['inventory_number' => 'INV-1', 'name' => 'Гематологический анализатор']);
        $office = $room->floor->building->office;
        $this->actingAs(User::factory()->role(Role::Accountant)->create());

        // Открытие с экрана списка.
        $this->get(route('inventories.index'))->assertOk()->assertSee('Открыть инвентаризацию');
        $this->post(route('inventories.store'), ['office_id' => $office->id, 'name' => 'Годовая', 'order_number' => 'ПР-7'])
            ->assertRedirect();
        $inventory = Inventory::sole();
        $item = $inventory->items()->sole();

        $this->get(route('inventories.show', $inventory))->assertOk()
            ->assertSee('Гематологический анализатор')->assertSee('Не проверено');
        $this->get(route('inventories.room', [$inventory, $room]))->assertOk()->assertSee('INV-1');
        $this->get(route('labels', ['inventory' => $inventory->id]))->assertOk()->assertSee('/a/'.$asset->qr_code);

        // Скан этикетки: карточка по qr_code, с нее – отметка.
        $this->get('/a/'.$asset->qr_code)->assertOk()->assertSee('Идет инвентаризация');
        $this->postJson(route('inventories.check', $inventory), ['mark' => 'misplaced', 'asset_id' => $asset->id, 'found_room_id' => $store->id])
            ->assertOk()->assertJson(['mark' => 'misplaced']);
        $this->assertSame($store->id, $item->fresh()->found_room_id);

        // Отметка по строке ведомости и фильтр по состоянию.
        $this->postJson(route('inventories.mark', [$inventory, $item]), ['mark' => 'found'])->assertOk();
        $this->get(route('inventories.show', [$inventory, 'mark' => 'missing']))->assertOk()->assertSee('Строк нет');

        // Излишек без карточки и ведомость расхождений на печать.
        $this->postJson(route('inventories.check', $inventory), ['mark' => 'surplus', 'note' => 'Шкаф без номера', 'found_room_id' => $store->id])->assertOk();
        $this->get(route('inventories.report', $inventory))->assertOk()->assertSee('Шкаф без номера');

        $this->post(route('inventories.close', $inventory))->assertRedirect();
        $this->assertSame(InventoryStatus::Closed, $inventory->fresh()->status);
    }

    public function test_viewer_cannot_mark(): void
    {
        $room = $this->room();
        $asset = $this->asset($room, ['inventory_number' => 'INV-1']);
        $inventory = app(Inventories::class)->open($room->floor->building->office, ['name' => 'Годовая']);
        $item = $inventory->items()->sole();

        $this->actingAs(User::factory()->role(Role::Viewer)->create());
        $this->get(route('inventories.show', $inventory))->assertOk();
        $this->get('/a/'.$asset->qr_code)->assertOk()->assertSee('Отмечать может бухгалтер или инженер');
        $this->postJson(route('inventories.mark', [$inventory, $item]), ['mark' => 'found'])->assertForbidden();
        $this->post(route('inventories.store'), ['office_id' => $room->floor->building->office_id, 'name' => 'Своя'])->assertForbidden();
    }
}
