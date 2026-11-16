<?php

namespace App\Http\Controllers;

use App\Enums\Area;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Contractor;
use App\Models\Employee;
use App\Models\Floor;
use App\Services\AssetMover;
use App\Services\FloorPlans;
use App\Services\Inventories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Единица учета: карточка и расстановка на плане (место меняет только AssetMover). */
class AssetController extends Controller
{
    public function show(Request $request, Asset $asset, Inventories $inventories): View
    {
        $asset->load(['category.layer', 'model', 'office', 'floor.building', 'room', 'mol', 'employee', 'contractor',
            'maintenancePlans', 'maintenanceRecords', 'moves.toOffice', 'moves.toRoom', 'moves.user',
            'attachments' => fn ($q) => $q->latest('id'), 'attachments.user']);
        $canAssets = $request->user()->edits(Area::Assets);

        return view('assets.show', [
            'asset' => $asset,
            // Скан QR ведет сюда же: если идет инвентаризация, в карточке отмечают "на месте".
            'check' => $inventories->cardContext($asset),
            'canAssets' => $canAssets,
            'categories' => $canAssets ? Category::with('layer')->orderBy('layer_id')->orderBy('sort')->orderBy('name')->get() : collect(),
            'models' => $canAssets ? AssetModel::orderBy('name')->get(['id', 'name', 'manufacturer']) : collect(),
            'contractors' => $canAssets ? Contractor::orderBy('name')->get(['id', 'name']) : collect(),
            'employees' => $canAssets && $asset->office_id
                ? Employee::working()->whereHas('offices', fn ($q) => $q->whereKey($asset->office_id))->orderBy('full_name')->get(['id', 'full_name'])
                : collect(),
        ]);
    }

    /**
     * Правка карточки: учет, медицинское изделие, покупка и гарантия. Место и закрепление
     * здесь не меняются – для МОЛ и пользователя своя форма (assign), она идет через AssetMover.
     */
    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'model_id' => ['nullable', 'exists:asset_models,id'],
            'name' => ['nullable', 'string', 'max:191'],
            'status' => ['required', Rule::enum(AssetStatus::class)],
            'inventory_number' => ['nullable', 'string', 'max:64', Rule::unique('assets', 'inventory_number')->ignore($asset)],
            'serial_number' => ['nullable', 'string', 'max:128'],
            'department' => ['nullable', 'string', 'max:191'],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'registration_until' => ['nullable', 'date'],
            'gsi_number' => ['nullable', 'string', 'max:64'],
            'manufactured_year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'commissioned_at' => ['nullable', 'date'],
            'purchased_at' => ['nullable', 'date'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'supplier' => ['nullable', 'string', 'max:191'],
            'warranty_until' => ['nullable', 'date'],
            'contractor_id' => ['nullable', 'exists:contractors,id'],
            'written_off_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['inventory_number.unique' => 'Такой инвентарный номер уже есть.']);

        $asset->update($data);

        return back()->with('ok', 'Сохранено.');
    }

    /** Закрепление: МОЛ и пользователь – только через AssetMover (смена МОЛ – передача с актом). */
    public function assign(Request $request, Asset $asset, AssetMover $mover): RedirectResponse
    {
        $data = $request->validate([
            'mol_id' => ['nullable', 'exists:employees,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'comment' => ['nullable', 'string', 'max:191'],
        ]);

        $move = $mover->move($asset, [
            'mol_id' => $data['mol_id'] ?: null,
            'employee_id' => $data['employee_id'] ?: null,
        ], $data['comment'] ?: null);

        return back()->with('ok', $move ? 'Закрепление изменено, запись в истории.' : 'Без изменений.');
    }

    /** Новая единица прямо с плана: поставили точку – завели карточку с минимумом полей. */
    public function store(Request $request, Floor $floor, AssetMover $mover, FloorPlans $plans): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['nullable', 'string', 'max:191'],
            'inventory_number' => ['nullable', 'string', 'max:64', 'unique:assets,inventory_number'],
            'serial_number' => ['nullable', 'string', 'max:128'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
        ], ['inventory_number.unique' => 'Такой инвентарный номер уже есть.']);
        abort_if($floor->building->is_candidate, 422, 'Здание проекта: сюда – только варианты расстановки.');

        $asset = DB::transaction(function () use ($data, $floor, $mover, $plans) {
            // Филиал – сразу при создании (он обязателен), помещение и точку ставит AssetMover.
            $asset = Asset::create(array_intersect_key($data, array_flip(['category_id', 'name', 'inventory_number', 'serial_number']))
                + ['office_id' => $floor->building->office_id]);
            $mover->move($asset, $this->target($floor, $plans, (float) $data['x'], (float) $data['y']), 'Поставлено на план');

            return $asset;
        });

        return response()->json(['ok' => true, 'asset' => FloorController::assetPoint($asset->fresh(['category.layer', 'model', 'maintenancePlans']), (int) config('services.alerts.maintenance_days')), 'message' => 'Добавлено.']);
    }

    /** Поставить, перетащить или повернуть на плане. Другое помещение или филиал – перемещение с историей. */
    public function place(Request $request, Asset $asset, AssetMover $mover, FloorPlans $plans): JsonResponse
    {
        $data = $request->validate([
            'floor_id' => ['required', 'exists:floors,id'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'rotation' => ['nullable', 'numeric'],
        ]);
        $floor = Floor::with('building')->findOrFail($data['floor_id']);

        // Поворот на месте – не перестановка: помещение не пересчитываем, иначе на этаже
        // без обведенных контуров оно обнулилось бы ни за что.
        $target = $this->stays($asset, $floor, (float) $data['x'], (float) $data['y'])
            ? ['floor_id' => $floor->id]
            : $this->target($floor, $plans, (float) $data['x'], (float) $data['y']);
        if (array_key_exists('rotation', $data) && $data['rotation'] !== null) {
            $target['rotation'] = self::degrees((float) $data['rotation']);
        }
        $move = $mover->move($asset, $target);
        $asset->refresh()->load(['room', 'category.layer', 'model', 'maintenancePlans']);

        return response()->json([
            'ok' => true,
            'room' => $asset->room?->title,
            'asset' => FloorController::assetPoint($asset, (int) config('services.alerts.maintenance_days')),
            'message' => $move ? 'Перемещено: '.($asset->room?->title ?? 'вне помещений').'.' : null,
        ]);
    }

    /**
     * Перенести разом выделенные на плане единицы: все сдвигаются на один вектор, взаимное
     * расположение сохраняется, помещение у каждой – по новому месту (AssetMover::moveMany,
     * одна транзакция; смена филиала или МОЛ соберется в черновики актов по направлениям).
     */
    /**
     * Снять выделенное с плана: единицы остаются в филиале, но без этажа, помещения и точки –
     * как "не расставлено". Через AssetMover, поэтому уход из помещения виден в истории.
     */
    public function unplaceMany(Request $request, Floor $floor, AssetMover $mover): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);
        $assets = Asset::whereIn('id', $data['ids'])->where('floor_id', $floor->id)->get();
        abort_if($assets->isEmpty(), 422, 'Выделенные единицы не найдены на этом этаже.');
        $mover->moveMany($assets, ['room_id' => null, 'floor_id' => null], 'Снято с плана');

        return response()->json([
            'ok' => true,
            'ids' => $assets->pluck('id'),
            'unplaced' => $assets->map(fn (Asset $a) => ['id' => $a->id, 'title' => $a->title, 'inv' => $a->inventory_number]),
            'message' => 'Снято с плана единиц: '.$assets->count().'. Их можно поставить заново.',
        ]);
    }

    public function placeMany(Request $request, Floor $floor, AssetMover $mover, FloorPlans $plans): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'dx' => ['required', 'numeric'],
            'dy' => ['required', 'numeric'],
            'rotation' => ['nullable', 'numeric'],
        ]);
        abort_if($floor->building->is_candidate, 422, 'Здание проекта: сюда – только варианты расстановки.');

        $assets = Asset::whereIn('id', $data['ids'])->whereNotNull('x')->get();
        abort_if($assets->isEmpty(), 422, 'Выделенные единицы не найдены.');
        $rotation = isset($data['rotation']) ? self::degrees((float) $data['rotation']) : null;

        $turn = $rotation === null ? [] : ['rotation' => $rotation];
        // Только поворот (сдвига нет) – места не касаемся вовсе.
        $acts = (float) $data['dx'] === 0.0 && (float) $data['dy'] === 0.0
            ? $mover->moveMany($assets, $turn + ['floor_id' => $floor->id], 'Поворот группой на плане')
            : $mover->moveMany($assets, fn (Asset $a) => $this->target($floor, $plans, $a->x + $data['dx'], $a->y + $data['dy']) + $turn,
                'Перенос группой на плане');

        $days = (int) config('services.alerts.maintenance_days');
        $points = $assets->map(fn (Asset $a) => FloorController::assetPoint(
            $a->fresh()->load(['category.layer', 'model', 'maintenancePlans']), $days))->values();
        $message = 'Перенесено единиц: '.$assets->count().'.';
        if ($acts) {
            $message .= ' Черновиков актов: '.count($acts).'.';
        }

        return response()->json(['ok' => true, 'assets' => $points, 'message' => $message]);
    }

    /** Единица осталась на той же точке того же этажа (поворот, повторное сохранение). */
    private function stays(Asset $asset, Floor $floor, float $x, float $y): bool
    {
        return (int) $asset->floor_id === $floor->id && $asset->x !== null
            && abs((float) $asset->x - $x) < 0.0005 && abs((float) $asset->y - $y) < 0.0005;
    }

    /** Угол в 0…359,9 градуса: сколько бы ни накрутили колесом. */
    private static function degrees(float $rotation): float
    {
        return round(fmod(fmod($rotation, 360) + 360, 360), 1);
    }

    private function target(Floor $floor, FloorPlans $plans, float $x, float $y): array
    {
        return [
            'floor_id' => $floor->id,
            'room_id' => $plans->roomAt($floor, $x, $y)?->id,
            'x' => round($x, 3),
            'y' => round($y, 3),
        ];
    }
}
