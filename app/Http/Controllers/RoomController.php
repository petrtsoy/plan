<?php

namespace App\Http\Controllers;

use App\Models\Floor;
use App\Models\Room;
use App\Services\Specs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Помещения с плана: контур обводят на этаже, свойства – в той же панели. */
class RoomController extends Controller
{
    public function store(Request $request, Floor $floor): JsonResponse
    {
        $room = $floor->rooms()->create($this->validated($request, true));

        return response()->json($this->payload($room) + ['message' => "Помещение \"{$room->title}\" добавлено."]);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $data = $this->validated($request, false, $room);
        // Новый контур – площадь заново, если ее не ввели вместе с ним.
        if (isset($data['polygon']) && ! $request->filled('area_m2')) {
            $data['area_m2'] = null;
        }
        $room->update($data);

        return response()->json($this->payload($room) + ['message' => 'Сохранено.']);
    }

    private function payload(Room $room): array
    {
        return [
            'ok' => true,
            'id' => $room->id,
            'area' => (float) $room->area_m2,
            'specs' => (object) ($room->specs ?? []),
            'responsible' => $room->responsible?->full_name,
        ];
    }

    private function validated(Request $request, bool $creating, ?Room $room = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:32'],
            'name' => [$required, 'string', 'max:191'],
            'purpose' => ['nullable', 'string', 'max:64'],
            'department' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'responsible_id' => ['nullable', 'integer', 'exists:employees,id'],
            // Кабинет в it.his.kz: один наш – один их, иначе техника поедет не туда.
            'it_location_id' => ['nullable', 'integer', 'min:1', Rule::unique('rooms', 'it_location_id')->ignore($room)],
            'specs' => ['nullable', 'array'],
            'polygon' => [$required, 'array', 'min:3', 'max:500'],
            'polygon.*' => ['array', 'size:2'],
            'polygon.*.*' => ['numeric'],
        ], [
            'polygon.min' => 'Контур – хотя бы три точки.',
            'it_location_id.unique' => 'Этот кабинет it.his.kz уже привязан к другому помещению.',
        ]);

        if (array_key_exists('specs', $data)) {
            $data['specs'] = Specs::values((array) $data['specs'], keepNo: true);
        }

        return $data;
    }
}
