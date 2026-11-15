<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ItExchange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API для it.his.kz (контракт docs/it-exchange.md). Ключ – в заголовке X-Api-Key,
 * задается в разделе "Настройки" ("it.his.kz" => "Ключ для it.his.kz: события перемещений").
 */
class ItController extends Controller
{
    /** Какой ответ на какую ошибку обработки: по ним it.his.kz решает, повторять ли. */
    private const CODES = [
        'device_not_found' => 404,
        'office_not_found' => 422,
    ];

    /** Устройство переместили в it.his.kz: POST /api/it/moves после DeviceMover::move. */
    public function moves(Request $request, ItExchange $exchange): JsonResponse
    {
        if (! ItExchange::incomingKeyMatches($request->header('X-Api-Key'))) {
            return response()->json(['status' => 'error', 'error' => 'unauthorized'], 401);
        }

        $event = $request->validate([
            'move_id' => ['required', 'integer', 'min:1'],
            'device_id' => ['nullable', 'integer', 'min:1'],
            'inv_number' => ['nullable', 'string', 'max:64'],
            'office_hr_id' => ['required', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'employee_hr_id' => ['present', 'nullable', 'integer', 'min:1'],
            'moved_at' => ['nullable', 'date'],
            'moved_by' => ['nullable', 'array'],
            'moved_by.iin' => ['nullable', 'string', 'size:12'],
            'moved_by.name' => ['nullable', 'string', 'max:191'],
            'comment' => ['nullable', 'string', 'max:191'],
        ]);

        $result = $exchange->receive($event);
        $code = self::CODES[$result['error'] ?? ''] ?? 200;

        return response()->json($result, $code, [], JSON_UNESCAPED_UNICODE);
    }
}
