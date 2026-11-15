<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\InventoryMark;
use App\Enums\InventoryStatus;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Office;
use App\Models\Room;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Инвентаризация: ведомость, отметки комиссии, закрытие.
 *
 * Ведомость снимается при открытии – в нее попадает все, что числится в филиале
 * (или в выбранных помещениях) с местом и МОЛ на тот момент: дальнейшие перемещения
 * ожидаемое место в строке не меняют, иначе сверять было бы нечего. Комиссия
 * отмечает "на месте" / "не на своем месте" (с фактическим помещением) / "не найдено",
 * незнакомое – излишком. Место по итогам пересчитывает закрытие, и только через
 * AssetMover (история и акты передачи – оттуда), с комментарием про инвентаризацию.
 *
 * В филиале идет не больше одной инвентаризации: иначе со скана QR непонятно,
 * в какую ведомость ставить отметку.
 */
class Inventories
{
    public function __construct(private AssetMover $mover) {}

    /**
     * Открыть инвентаризацию и снять ведомость.
     *
     * @param  array  $attrs  name, order_number, order_date, notes
     * @param  array{rooms?: list<int>, categories?: list<int>}  $scope  пусто – весь филиал
     */
    public function open(Office $office, array $attrs, array $scope = []): Inventory
    {
        if (Inventory::where('office_id', $office->id)->where('status', InventoryStatus::Open)->exists()) {
            throw ValidationException::withMessages(['office_id' => 'В этом филиале уже идет инвентаризация – ее нужно закрыть.']);
        }

        $rooms = array_values(array_unique(array_map('intval', $scope['rooms'] ?? [])));
        $categories = array_values(array_unique(array_map('intval', $scope['categories'] ?? [])));
        if ($rooms) {
            $mine = Room::whereIn('rooms.id', $rooms)
                ->whereHas('floor.building', fn ($q) => $q->where('office_id', $office->id))
                ->count();
            if ($mine !== count($rooms)) {
                throw ValidationException::withMessages(['rooms' => 'Помещение не из этого филиала.']);
            }
        }

        return DB::transaction(function () use ($office, $attrs, $rooms, $categories) {
            $inventory = Inventory::create(array_intersect_key($attrs, array_flip(['order_number', 'order_date', 'notes'])) + [
                'office_id' => $office->id,
                'name' => ($attrs['name'] ?? null) ?: 'Инвентаризация '.today()->format('d.m.Y'),
                'status' => InventoryStatus::Open,
                'scope' => array_filter(['rooms' => $rooms, 'categories' => $categories]) ?: null,
                'started_at' => now(),
                'user_id' => Auth::id(),
            ]);

            // Списанное сверять незачем; у нерасставленного ожидаемое помещение пустое.
            $assets = Asset::where('office_id', $office->id)
                ->where('status', '!=', AssetStatus::WrittenOff)
                ->when($rooms, fn ($q) => $q->whereIn('room_id', $rooms))
                ->when($categories, fn ($q) => $q->whereIn('category_id', $categories))
                ->orderBy('id')
                ->get(['id', 'room_id', 'mol_id']);

            $now = now();
            $assets->map(fn (Asset $asset) => [
                'inventory_id' => $inventory->id,
                'asset_id' => $asset->id,
                'expected_room_id' => $asset->room_id,
                'expected_mol_id' => $asset->mol_id,
                'mark' => InventoryMark::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->chunk(500)->each(fn (Collection $chunk) => InventoryItem::insert($chunk->values()->all()));

            return $inventory;
        });
    }

    /** Отметка по строке ведомости. "Не на своем месте" – с фактическим помещением. */
    public function mark(InventoryItem $item, InventoryMark $mark, ?int $foundRoomId = null, ?string $note = null): InventoryItem
    {
        $this->assertOpen($item->inventory);
        if ($item->mark === InventoryMark::Surplus) {
            throw ValidationException::withMessages(['mark' => 'Это излишек, а не строка ведомости.']);
        }
        if ($mark === InventoryMark::Surplus) {
            throw ValidationException::withMessages(['mark' => 'Излишек добавляют отдельной строкой.']);
        }

        if ($mark === InventoryMark::Misplaced) {
            if (! $foundRoomId) {
                throw ValidationException::withMessages(['found_room_id' => 'Укажите, в каком помещении нашли.']);
            }
            // Нашли там, где и числится, – это "на месте", как бы ни нажали.
            if ((int) $foundRoomId === (int) $item->expected_room_id) {
                $mark = InventoryMark::Found;
            }
        }

        $item->update([
            'mark' => $mark,
            'found_room_id' => match ($mark) {
                InventoryMark::Found => $item->expected_room_id,
                InventoryMark::Misplaced => $foundRoomId,
                default => null,
            },
            'note' => $note,
            'checked_at' => $mark === InventoryMark::Pending ? null : now(),
            'checked_by' => $mark === InventoryMark::Pending ? null : Auth::id(),
        ]);

        return $item;
    }

    /**
     * Излишек: нашли то, чего в ведомости нет. Без единицы учета ($asset = null) –
     * описание в note: такого нет и в системе, заводить карточку решают после.
     */
    public function addSurplus(Inventory $inventory, ?Asset $asset, ?int $foundRoomId = null, ?string $note = null): InventoryItem
    {
        $this->assertOpen($inventory);
        if (! $asset && ! $note) {
            throw ValidationException::withMessages(['note' => 'Опишите, что нашли.']);
        }
        if ($asset && $inventory->items()->where('asset_id', $asset->id)->exists()) {
            throw ValidationException::withMessages(['asset_id' => 'Эта единица уже в ведомости – отметьте ее там.']);
        }

        return $inventory->items()->create([
            'asset_id' => $asset?->id,
            // Где числится то, что нашли не у себя: расхождение видно сразу в ведомости.
            'expected_room_id' => $asset?->room_id,
            'expected_mol_id' => $asset?->mol_id,
            'found_room_id' => $foundRoomId,
            'mark' => InventoryMark::Surplus,
            'note' => $note,
            'checked_at' => now(),
            'checked_by' => Auth::id(),
        ]);
    }

    /**
     * Закрыть: по строкам "не на своем месте" и излишкам с фактическим помещением
     * единицы переезжают туда – через AssetMover, то есть с историей, а при смене
     * филиала или МОЛ еще и с черновиком акта передачи. "Не найдено" место не меняет:
     * списывать или искать дальше решают по ведомости расхождений.
     *
     * @return int сколько единиц переставлено
     */
    public function close(Inventory $inventory, ?string $notes = null): int
    {
        $this->assertOpen($inventory);

        return DB::transaction(function () use ($inventory, $notes) {
            $moved = 0;
            $comment = 'Инвентаризация '.($inventory->order_number ? "{$inventory->order_number} – " : '').$inventory->name;

            $items = $inventory->items()
                ->whereIn('mark', [InventoryMark::Misplaced, InventoryMark::Surplus])
                ->whereNotNull('found_room_id')->whereNotNull('asset_id')
                ->with(['asset.category', 'foundRoom'])->get();

            foreach ($items as $item) {
                $asset = $item->asset;
                // Удаленную карточку не трогаем, да и переставлять ее некуда.
                if (! $asset || $asset->trashed() || (int) $asset->room_id === (int) $item->found_room_id) {
                    continue;
                }
                $moved += $this->mover->move($asset, ['room_id' => $item->found_room_id] + $this->point($item->foundRoom), $comment) ? 1 : 0;
            }

            $inventory->update([
                'status' => InventoryStatus::Closed,
                'closed_at' => now(),
                'notes' => $notes ?? $inventory->notes,
            ]);

            return $moved;
        });
    }

    /** Идущая инвентаризация, в которой можно отметить эту единицу (для скана QR). */
    public function openFor(Asset $asset): ?Inventory
    {
        $listed = InventoryItem::where('asset_id', $asset->id)
            ->whereHas('inventory', fn ($q) => $q->where('status', InventoryStatus::Open))
            ->first()?->inventory;

        return $listed ?? Inventory::where('office_id', $asset->office_id)->where('status', InventoryStatus::Open)->first();
    }

    /**
     * Контекст для карточки единицы (она же – адрес QR этикетки): идущая инвентаризация,
     * строка ведомости по этой единице и помещения филиала для отметки "не на своем месте".
     *
     * @return array{inventory: Inventory, item: ?InventoryItem, rooms: Collection}|null
     */
    public function cardContext(Asset $asset): ?array
    {
        $inventory = $this->openFor($asset);
        if (! $inventory) {
            return null;
        }

        return [
            'inventory' => $inventory,
            'item' => $inventory->items()->with(['expectedRoom', 'foundRoom'])->where('asset_id', $asset->id)->first(),
            'rooms' => Room::whereHas('floor.building', fn ($q) => $q->where('office_id', $inventory->office_id))
                ->orderBy('floor_id')->orderBy('number')->get(),
        ];
    }

    /** Сколько строк в каждом состоянии: значение отметки => число. */
    public function counts(Inventory $inventory): array
    {
        $counts = $inventory->items()->selectRaw('mark, count(*) as n')->groupBy('mark')->pluck('n', 'mark')->all();

        $by = [];
        foreach (InventoryMark::cases() as $case) {
            $by[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return $by + ['total' => array_sum($by)];
    }

    /** Расхождения для ведомости по МОЛ: все, кроме "на месте", с местами и единицами. */
    public function discrepancies(Inventory $inventory): Collection
    {
        return $inventory->items()
            ->where('mark', '!=', InventoryMark::Found)
            ->with(['asset.category', 'expectedRoom', 'expectedMol', 'foundRoom'])
            ->get()
            ->sortBy([
                fn (InventoryItem $i) => $i->expectedMol?->full_name ?? 'яяя',
                fn (InventoryItem $i) => $i->expectedRoom?->title ?? '',
                fn (InventoryItem $i) => $i->asset?->title ?? ($i->note ?? ''),
            ])
            ->values();
    }

    private function assertOpen(Inventory $inventory): void
    {
        if ($inventory->status !== InventoryStatus::Open) {
            throw ValidationException::withMessages(['inventory' => "Инвентаризация \"{$inventory->name}\" уже завершена."]);
        }
    }

    /**
     * Точка на плане для переехавшей единицы: метка помещения из экспликации, иначе
     * середина габаритов контура. Точнее поставит инженер – перетащив на плане.
     */
    private function point(?Room $room): array
    {
        if ($room?->label_x !== null && $room?->label_y !== null) {
            return ['x' => $room->label_x, 'y' => $room->label_y];
        }
        if (! $room?->polygon) {
            return [];
        }
        $xs = array_column($room->polygon, 0);
        $ys = array_column($room->polygon, 1);

        return ['x' => round((min($xs) + max($xs)) / 2, 3), 'y' => round((min($ys) + max($ys)) / 2, 3)];
    }
}
