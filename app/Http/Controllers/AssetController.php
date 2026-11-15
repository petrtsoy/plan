<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Floor;
use App\Services\AssetMover;
use App\Services\FloorPlans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Единица учета: карточка и расстановка на плане (место меняет только AssetMover). */
class AssetController extends Controller
{
    public function show(Asset $asset): View
    {
        $asset->load(['category.layer', 'model', 'office', 'floor.building', 'room', 'mol', 'employee', 'contractor',
            'maintenancePlans', 'maintenanceRecords', 'moves.toOffice', 'moves.toRoom', 'moves.user']);

        return view('assets.show', ['asset' => $asset]);
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

    /** Поставить или перетащить на плане. Другое помещение или филиал – перемещение с историей. */
    public function place(Request $request, Asset $asset, AssetMover $mover, FloorPlans $plans): JsonResponse
    {
        $data = $request->validate([
            'floor_id' => ['required', 'exists:floors,id'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
        ]);
        $floor = Floor::with('building')->findOrFail($data['floor_id']);

        $move = $mover->move($asset, $this->target($floor, $plans, (float) $data['x'], (float) $data['y']));
        $asset->refresh()->load(['room', 'category.layer', 'model', 'maintenancePlans']);

        return response()->json([
            'ok' => true,
            'room' => $asset->room?->title,
            'asset' => FloorController::assetPoint($asset, (int) config('services.alerts.maintenance_days')),
            'message' => $move ? 'Перемещено: '.($asset->room?->title ?? 'вне помещений').'.' : null,
        ]);
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
