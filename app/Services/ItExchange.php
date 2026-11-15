<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetMove;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Прием событий перемещения из it.his.kz (контракт docs/it-exchange.md).
 *
 * Место ИТ-техники первично в it.his.kz: после каждого DeviceMover::move оттуда
 * приходит событие, здесь оно ложится перемещением через AssetMover с origin = it
 * (строка в отдельный черновик акта, в 1С уйдет при оформлении, обратно в it – нет).
 * Повторная доставка того же события ничего не делает: защита – уникальный
 * asset_moves.it_move_id.
 *
 * Сопоставление: устройство – assets.it_device_id, в первый раз по инвентарному
 * номеру (связка запоминается); кабинет – rooms.it_location_id; филиалы и сотрудники
 * обеих систем из HR, поэтому по hr_id.
 */
class ItExchange
{
    public function __construct(private AssetMover $mover) {}

    /** Ключ из раздела "Настройки", которым it.his.kz присылает события. Пусто – прием выключен. */
    public static function incomingKeyMatches(?string $key): bool
    {
        $expected = (string) config('services.it.incoming_key');

        return $expected !== '' && hash_equals($expected, (string) $key);
    }

    /**
     * Обработать событие. Возвращает тело ответа; при ошибке – status = error и код
     * в error (коды и статусы ответа – в контракте).
     */
    public function receive(array $event): array
    {
        // Кто переместил в it.his.kz: если такой человек есть и здесь, перемещение, журнал
        // и черновик акта будут за ним; иначе – без автора, только имя в примечании.
        $actor = $this->actor($event['moved_by'] ?? []);
        if ($actor) {
            Auth::setUser($actor);
        }

        $asset = $this->findAsset($event);
        if (! $asset) {
            Audit::log('it_exchange', 0, 'device_not_found', [
                'device_id' => $event['device_id'] ?? null,
                'inv_number' => $event['inv_number'] ?? null,
                'move_id' => $event['move_id'],
            ], 'Событие из it.his.kz: устройство не найдено');

            return [
                'status' => 'error',
                'error' => 'device_not_found',
                'message' => 'Устройство не найдено: ни по связке, ни по инвентарному номеру. Нужно завести единицу учета в plan или проверить инвентарный номер.',
            ];
        }

        // Повтор доставки: AssetMover такое перемещение не создаст, но ответить надо тем же.
        $already = AssetMove::where('it_move_id', $event['move_id'])->first();
        if ($already) {
            return ['status' => 'success', 'duplicate' => true, 'asset_id' => $already->asset_id, 'move_id' => $already->id, 'warnings' => []];
        }

        $office = Office::where('hr_id', $event['office_hr_id'])->first();
        if (! $office) {
            Audit::log('it_exchange', $asset->id, 'office_not_found', ['office_hr_id' => $event['office_hr_id']], 'Событие из it.his.kz: филиал не найден');

            return ['status' => 'error', 'error' => 'office_not_found', 'message' => 'Филиал с таким hr_id в plan не найден – нужна синхронизация hr:sync.'];
        }

        $warnings = [];
        [$to, $warnings] = $this->place($event, $office, $warnings);
        if (array_key_exists('employee_hr_id', $event)) {
            $employee = $event['employee_hr_id'] ? Employee::where('hr_id', $event['employee_hr_id'])->first() : null;
            if ($event['employee_hr_id'] && ! $employee) {
                $warnings[] = 'employee_not_matched';
            }
            $to['employee_id'] = $employee?->id;
        }

        $move = $this->mover->move($asset, $to, $this->comment($event), itMoveId: (int) $event['move_id']);
        // Время перемещения – как в it.his.kz, а не когда событие дошло; там время
        // с зоной (UTC), здесь, как и все даты, хранится в зоне приложения.
        if ($move && ! empty($event['moved_at'])) {
            $move->update(['moved_at' => Carbon::parse($event['moved_at'])->setTimezone(config('app.timezone'))]);
        }

        if ($warnings) {
            Audit::log('it_exchange', $asset->id, 'move_received', [
                'move_id' => $event['move_id'],
                'warnings' => $warnings,
                'location_id' => $event['location_id'] ?? null,
            ], 'Событие из it.his.kz принято с замечаниями');
        }

        return ['status' => 'success', 'duplicate' => false, 'asset_id' => $asset->id, 'move_id' => $move?->id, 'warnings' => $warnings];
    }

    /**
     * Единица учета по связке, иначе по инвентарному номеру – и тогда связка запоминается
     * (в журнале это видно как изменение карточки).
     */
    private function findAsset(array $event): ?Asset
    {
        $deviceId = $event['device_id'] ?? null;
        if ($deviceId && $asset = Asset::where('it_device_id', $deviceId)->first()) {
            return $asset;
        }

        $number = trim((string) ($event['inv_number'] ?? ''));
        if ($number === '') {
            return null;
        }
        // Занятую связку не перебиваем: это расхождение, а не первое сопоставление.
        $asset = Asset::where('inventory_number', $number)->whereNull('it_device_id')->first();
        if ($asset && $deviceId) {
            $asset->update(['it_device_id' => $deviceId]);
        }

        return $asset;
    }

    /**
     * Куда: кабинет it.his.kz – в помещение по rooms.it_location_id (оно же задает этаж
     * и филиал). Кабинет не сопоставлен или оказался в другом филиале – ставим единицу
     * нерасставленной в филиал события и говорим об этом в ответе.
     *
     * @return array{0: array, 1: list<string>}
     */
    private function place(array $event, Office $office, array $warnings): array
    {
        $locationId = $event['location_id'] ?? null;
        $room = $locationId ? Room::where('it_location_id', $locationId)->with('floor.building')->first() : null;

        if ($room && (int) $room->floor->building->office_id !== (int) $office->id) {
            $warnings[] = 'room_office_mismatch';
            $room = null;
        } elseif ($locationId && ! $room) {
            $warnings[] = 'room_not_matched';
        }

        if ($room) {
            return [['room_id' => $room->id], $warnings];
        }

        return [['office_id' => $office->id, 'room_id' => null, 'floor_id' => null], $warnings];
    }

    /** Примечание перемещения: что написали в it.his.kz и кто там переместил. */
    private function comment(array $event): string
    {
        $comment = trim((string) ($event['comment'] ?? '')) ?: 'Перемещение в it.his.kz';
        $name = trim((string) ($event['moved_by']['name'] ?? ''));

        return Str::limit($name === '' ? $comment : "{$comment} ({$name})", 190);
    }

    private function actor(array $movedBy): ?User
    {
        $iin = trim((string) ($movedBy['iin'] ?? ''));

        return $iin === '' ? null : User::where('iin', $iin)->where('is_active', true)->first();
    }
}
