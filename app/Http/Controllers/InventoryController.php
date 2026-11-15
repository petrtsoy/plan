<?php

namespace App\Http\Controllers;

use App\Enums\Area;
use App\Enums\InventoryMark;
use App\Enums\InventoryStatus;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Office;
use App\Models\Room;
use App\Services\Inventories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Инвентаризация: список, ведомость с фильтрами и отметками, узкий экран помещения
 * для телефона, ведомость расхождений по МОЛ на печать. Все, что меняет ведомость,
 * идет через App\Services\Inventories.
 */
class InventoryController extends Controller
{
    public function __construct(private Inventories $inventories) {}

    public function index(): View
    {
        $inventories = Inventory::with('office')->withCount([
            'items',
            'items as pending_count' => fn ($q) => $q->where('mark', InventoryMark::Pending),
            'items as diff_count' => fn ($q) => $q->whereIn('mark', [InventoryMark::Misplaced, InventoryMark::Missing, InventoryMark::Surplus]),
        ])->orderByRaw("status = 'open' desc")->latest('id')->paginate(25);

        // Помещения всех филиалов разом: в форме открытия выбор сужается по выбранному филиалу.
        $rooms = Room::whereHas('floor.building', fn ($q) => $q->real())->with('floor.building')->orderBy('number')->get()
            ->groupBy(fn (Room $room) => (int) $room->floor->building->office_id)
            ->map(fn ($list) => $list->map(fn (Room $room) => ['id' => $room->id, 'title' => $room->title, 'floor' => $room->floor->path])->values());

        return view('inventories.index', [
            'inventories' => $inventories,
            'offices' => Office::where('is_active', true)->orderByDesc('is_branch')->orderBy('name')->get(),
            'categories' => Category::with('layer')->orderBy('name')->get(),
            'roomsByOffice' => $rooms,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'office_id' => ['required', 'exists:offices,id'],
            'name' => ['nullable', 'string', 'max:191'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rooms' => ['nullable', 'array'],
            'rooms.*' => ['integer', 'exists:rooms,id'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', 'exists:categories,id'],
        ]);

        $inventory = $this->inventories->open(
            Office::findOrFail($data['office_id']),
            $data,
            ['rooms' => $data['rooms'] ?? [], 'categories' => $data['categories'] ?? []],
        );

        return redirect()->route('inventories.show', $inventory)
            ->with('ok', 'Ведомость снята: '.$inventory->items()->count().' строк.');
    }

    public function show(Request $request, Inventory $inventory): View
    {
        $filters = $request->validate([
            'room' => ['nullable', 'integer'],
            'mol' => ['nullable', 'integer'],
            'mark' => ['nullable', 'string', 'in:'.implode(',', array_keys(InventoryMark::options()))],
            'q' => ['nullable', 'string', 'max:191'],
        ]);

        $items = $inventory->items()
            ->with(['asset.category', 'expectedRoom', 'expectedMol', 'foundRoom', 'checker'])
            ->when($filters['room'] ?? null, fn ($q, $room) => $q->where(fn ($w) => $w->where('expected_room_id', $room)->orWhere('found_room_id', $room)))
            ->when($filters['mol'] ?? null, fn ($q, $mol) => $q->where('expected_mol_id', $mol))
            ->when($filters['mark'] ?? null, fn ($q, $mark) => $q->where('mark', $mark))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($w) => $w
                ->whereHas('asset', fn ($a) => $a->where('name', 'like', "%{$text}%")->orWhere('inventory_number', 'like', "%{$text}%")->orWhere('serial_number', 'like', "%{$text}%"))
                ->orWhere('note', 'like', "%{$text}%")))
            ->orderByRaw('expected_room_id is null, expected_room_id')->orderBy('id')
            ->paginate(100)->withQueryString();

        return view('inventories.show', [
            'inventory' => $inventory->load('office', 'user'),
            'items' => $items,
            'counts' => $this->inventories->counts($inventory),
            'rooms' => $this->rooms($inventory),
            'mols' => $this->mols($inventory),
            'progress' => $this->progress($inventory),
            'filters' => $filters,
            'canCheck' => $this->canCheck($inventory),
        ]);
    }

    /** Телефон: одно помещение, ожидаемые единицы, отметка одним касанием. */
    public function room(Inventory $inventory, Room $room): View
    {
        $items = $inventory->items()
            ->where(fn ($q) => $q->where('expected_room_id', $room->id)->orWhere('found_room_id', $room->id))
            ->with(['asset.category', 'expectedRoom', 'expectedMol', 'foundRoom'])
            ->orderByRaw('mark = \'pending\' desc')->orderBy('id')
            ->get();

        return view('inventories.room', [
            'inventory' => $inventory,
            'room' => $room->load('floor.building'),
            'items' => $items,
            'rooms' => $this->rooms($inventory),
            'canCheck' => $this->canCheck($inventory),
        ]);
    }

    /** Отметка по строке ведомости (с ведомости, с экрана помещения, с карточки единицы). */
    public function mark(Request $request, Inventory $inventory, InventoryItem $item): JsonResponse
    {
        abort_unless($item->inventory_id === $inventory->id, 404);
        $data = $this->markData($request);

        $item = $this->inventories->mark($item, InventoryMark::from($data['mark']), $data['found_room_id'] ?? null, $data['note'] ?? null);

        return response()->json([
            'ok' => true,
            'mark' => $item->mark->value,
            'label' => $item->mark->label(),
            'found_room' => $item->foundRoom()->first()?->title,
            'message' => 'Отмечено: '.mb_strtolower($item->mark->label()).'.',
        ]);
    }

    /**
     * Отметка по единице, а не по строке: скан QR этикетки. Если в ведомости ее нет –
     * это излишек (нашли то, что числится в другом месте или не числится вовсе).
     */
    public function check(Request $request, Inventory $inventory): JsonResponse
    {
        $data = $this->markData($request) + $request->validate([
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
        ]);

        $asset = isset($data['asset_id']) ? Asset::findOrFail($data['asset_id']) : null;
        $item = $asset ? $inventory->items()->where('asset_id', $asset->id)->first() : null;

        if ($item) {
            $item = $this->inventories->mark($item, InventoryMark::from($data['mark']), $data['found_room_id'] ?? null, $data['note'] ?? null);
        } else {
            $item = $this->inventories->addSurplus($inventory, $asset, $data['found_room_id'] ?? null, $data['note'] ?? null);
        }

        return response()->json([
            'ok' => true,
            'mark' => $item->mark->value,
            'label' => $item->mark->label(),
            'message' => 'Отмечено: '.mb_strtolower($item->mark->label()).'.',
        ]);
    }

    public function close(Request $request, Inventory $inventory): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $moved = $this->inventories->close($inventory, $data['notes'] ?? null);

        return redirect()->route('inventories.show', $inventory)
            ->with('ok', $moved ? "Инвентаризация завершена, переставлено единиц: {$moved}." : 'Инвентаризация завершена.');
    }

    /** Ведомость расхождений по МОЛ – на печать (@media print). */
    public function report(Inventory $inventory): View
    {
        return view('inventories.report', [
            'inventory' => $inventory->load('office', 'user'),
            'groups' => $this->inventories->discrepancies($inventory)->groupBy(fn (InventoryItem $i) => $i->expectedMol?->full_name ?? 'МОЛ не указан'),
            'counts' => $this->inventories->counts($inventory),
        ]);
    }

    private function markData(Request $request): array
    {
        return $request->validate([
            'mark' => ['required', 'string', 'in:'.implode(',', array_keys(InventoryMark::options()))],
            'found_room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);
    }

    private function canCheck(Inventory $inventory): bool
    {
        return $inventory->status === InventoryStatus::Open && auth()->user()->edits(Area::Inventory);
    }

    /** Помещения филиала – для выбора "нашли здесь". */
    private function rooms(Inventory $inventory)
    {
        return Room::whereHas('floor.building', fn ($q) => $q->where('office_id', $inventory->office_id))
            ->with('floor.building')->orderBy('floor_id')->orderBy('number')->get();
    }

    /** МОЛ, на которых что-то числится в этой ведомости. */
    private function mols(Inventory $inventory)
    {
        return Employee::whereIn('id', $inventory->items()->whereNotNull('expected_mol_id')->distinct()->pluck('expected_mol_id'))
            ->orderBy('full_name')->get();
    }

    /** Сколько проверено по помещениям: с этого списка комиссия заходит в помещение с телефона. */
    private function progress(Inventory $inventory): array
    {
        $rows = $inventory->items()
            ->selectRaw('expected_room_id, count(*) as n, sum(case when mark = \'pending\' then 1 else 0 end) as pending')
            ->groupBy('expected_room_id')->get();

        return $rows->keyBy(fn ($row) => (int) $row->expected_room_id)->all();
    }
}
