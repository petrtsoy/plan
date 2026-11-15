<?php

namespace App\Services\Outbox;

use App\Models\OutboxMessage;

/**
 * Документ перемещения ОС в 1С. Заглушка: записи в 1С еще нет – клиент
 * his/onec-odata пока только читает (справочник ОС, onec:sync), POST документа –
 * отдельная задача этапа 4. Поэтому configured() = false при любых настройках базы:
 * отправлять нечем, и OutboxSender не тратит на это попытки.
 */
class OnecDriver implements Driver
{
    public function configured(): bool
    {
        return false;
    }

    public function reason(): string
    {
        return 'клиент 1С не настроен: запись документа перемещения ОС еще не сделана';
    }

    public function send(OutboxMessage $message): ?string
    {
        throw new \LogicException('Клиент 1С на запись не сделан: отправлять нечем.');
    }
}
