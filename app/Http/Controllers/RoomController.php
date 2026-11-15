<?php

namespace App\Http\Controllers;

use App\Models\Floor;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Помещения с плана: контур обводят на этаже, свойства – в той же панели. */
class RoomController extends Controller
{
    public function store(Request $request, Floor $floor): JsonResponse
    {
        $room = $floor->rooms()->create($this->validated($request, true));

        return response()->json(['ok' => true, 'id' => $room->id, 'area' => (float) $room->area_m2, 'message' => "Помещение \"{$room->title}\" добавлено."]);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $data = $this->validated($request, false);
        // Новый контур – площадь заново, если ее не ввели вместе с ним.
        if (isset($data['polygon']) && ! $request->filled('area_m2')) {
            $data['area_m2'] = null;
        }
        $room->update($data);

        return response()->json(['ok' => true, 'id' => $room->id, 'area' => (float) $room->area_m2, 'message' => 'Сохранено.']);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'number' => ['nullable', 'string', 'max:32'],
            'name' => [$required, 'string', 'max:191'],
            'purpose' => ['nullable', 'string', 'max:64'],
            'department' => ['nullable', 'string', 'max:191'],
            'polygon' => [$required, 'array', 'min:3', 'max:500'],
            'polygon.*' => ['array', 'size:2'],
            'polygon.*.*' => ['numeric'],
        ], ['polygon.min' => 'Контур – хотя бы три точки.']);
    }
}
