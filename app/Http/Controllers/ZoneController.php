<?php

namespace App\Http\Controllers;

use App\Enums\ZoneKind;
use App\Models\Floor;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Зоны этажа: пожарный отсек, отдел, чистая зона, граница магнитного поля МРТ.
 * Лежат поверх помещений отдельным слоем – контур обводят так же, как помещение.
 */
class ZoneController extends Controller
{
    public function store(Request $request, Floor $floor): JsonResponse
    {
        $zone = $floor->zones()->create($this->validated($request, true));

        return response()->json(['ok' => true, 'zone' => FloorController::zoneData($zone), 'message' => "Зона \"{$zone->name}\" добавлена."]);
    }

    public function update(Request $request, Zone $zone): JsonResponse
    {
        $zone->update($this->validated($request, false));

        return response()->json(['ok' => true, 'zone' => FloorController::zoneData($zone->fresh()), 'message' => 'Сохранено.']);
    }

    /** Убрать зону с плана (мягко: в журнале остается). */
    public function destroy(Zone $zone): JsonResponse
    {
        $zone->delete();

        return response()->json(['ok' => true, 'message' => "Зона \"{$zone->name}\" убрана с плана."]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:191'],
            'kind' => [$required, Rule::enum(ZoneKind::class)],
            // Пусто – цвет по типу зоны (Zone::colorFor).
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'polygon' => [$required, 'array', 'min:3', 'max:500'],
            'polygon.*' => ['array', 'size:2'],
            'polygon.*.*' => ['numeric'],
        ], ['polygon.min' => 'Контур – хотя бы три точки.']);
    }
}
