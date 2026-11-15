<?php

namespace App\Services\Outbox;

use App\Models\OutboxMessage;

/**
 * Передача устройства в it.his.kz (его DeviceMover через API). Заглушка: метод API
 * еще не сделан – отдельная задача этапа 4. Как и у 1С, configured() = false:
 * сообщение ждет в очереди, попытки не горят.
 */
class ItDriver implements Driver
{
    public function configured(): bool
    {
        return false;
    }

    public function reason(): string
    {
        return 'связь с it.his.kz не настроена: метод "передать устройство" еще не сделан';
    }

    public function send(OutboxMessage $message): ?string
    {
        throw new \LogicException('Клиент it.his.kz на запись не сделан: отправлять нечем.');
    }
}
