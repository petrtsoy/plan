<?php

namespace App\Services\Outbox;

use App\Models\AssetMove;
use App\Models\Employee;
use App\Models\Office;
use App\Models\OutboxMessage;
use App\Models\Room;
use App\Services\Audit;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Отправка в it.his.kz (контракт docs/it-exchange.md): "передать устройство" – там это
 * DeviceMover::move, с номером акта, если акт уже оформлен.
 *
 * ИТ-единицу, перемещенную здесь, AssetMover ставит в outbox в той же транзакции; в
 * payload лежат наши ключи (так он не зависит от справочников it.his.kz), а в ids
 * внешней системы они переводятся при отправке: филиал и сотрудник – по hr_id (обе
 * системы зеркалят HR), помещение – по rooms.it_location_id. Перемещение, пришедшее
 * из it.his.kz (origin = it), обратно не отправляется – сообщения для него нет.
 */
class ItSender implements Sender
{
    public function configured(): bool
    {
        return filled(config('services.it.base_url')) && filled(config('services.it.api_key'));
    }

    public function reason(): string
    {
        return 'it.his.kz не настроен: в разделе "Настройки" не задан адрес или ключ';
    }

    public function send(OutboxMessage $message): ?string
    {
        if ($message->target !== 'it') {
            throw new RuntimeException("Сообщение {$message->id} не для it.his.kz.");
        }

        return match ($message->action) {
            'move' => $this->move($message),
            default => throw new RuntimeException("it.his.kz: неизвестное действие {$message->action}."),
        };
    }

    private function move(OutboxMessage $message): ?string
    {
        $payload = $message->payload;
        $body = [
            'device_id' => $payload['it_device_id'] ?? null,
            'inv_number' => $payload['inventory_number'] ?? null,
            'office_hr_id' => $payload['to_office_id'] ? Office::whereKey($payload['to_office_id'])->value('hr_id') : null,
            'location_id' => $payload['to_room_id'] ? Room::whereKey($payload['to_room_id'])->value('it_location_id') : null,
            'employee_hr_id' => $payload['to_employee_id'] ? Employee::whereKey($payload['to_employee_id'])->value('hr_id') : null,
            'transfer_number' => $payload['transfer_number'] ?? null,
            'comment' => $payload['comment'] ?? null,
        ];

        $data = $this->post('/api/plan/moves', $body);

        $subject = $message->subject;
        $asset = $subject instanceof AssetMove ? $subject->asset : null;

        // it.his.kz нашел устройство по инвентарному номеру – запоминаем связку.
        if (! empty($data['device_id']) && $asset && ! $asset->it_device_id) {
            $asset->update(['it_device_id' => (int) $data['device_id']]);
        }

        // Приняли не полностью (кабинет или сотрудник там не нашлись) – в журнал:
        // это сигнал поправить связку помещения или догнать hr:sync.
        $warnings = array_values(array_filter((array) ($data['warnings'] ?? [])));
        if ($warnings) {
            Audit::log('it_exchange', $asset?->id ?? 0, 'move_sent', [
                'warnings' => $warnings,
                'it_move_id' => $data['move_id'] ?? null,
                'location_id' => $body['location_id'],
                'employee_hr_id' => $body['employee_hr_id'],
            ], 'it.his.kz принял перемещение с замечаниями');
        }

        // move_id может не прийти: перестановку в то же место it.his.kz в историю не пишет.
        return isset($data['move_id']) ? (string) $data['move_id'] : null;
    }

    private function post(string $path, array $body): array
    {
        $baseUrl = (string) config('services.it.base_url');
        $key = (string) config('services.it.api_key');
        if ($baseUrl === '' || $key === '') {
            throw new RuntimeException('it.his.kz: в разделе "Настройки" не задан адрес или ключ.');
        }

        $response = Http::withHeaders(['X-Api-Key' => $key])
            ->timeout((int) config('services.it.timeout'))
            ->acceptJson()
            ->post($baseUrl.$path, $body);

        if ($response->failed()) {
            $error = $response->json('error') ?? $response->json('message') ?? mb_substr($response->body(), 0, 300);
            $text = "it.his.kz ответил {$response->status()}: {$error}";
            // Устройства там нет (404) или тело не прошло проверку (422 с errors) – повторы
            // не помогут. А вот незаведенный филиал (422 office_not_found), перегрузка и
            // 5xx проходят сами: после hr:sync в it.his.kz сообщение уйдет.
            $rejected = $response->status() === 404 || ($response->status() === 422 && $response->json('errors'));

            throw $rejected ? new Rejected($text) : new RuntimeException($text);
        }

        return (array) $response->json();
    }
}
