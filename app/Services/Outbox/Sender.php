<?php

namespace App\Services\Outbox;

use App\Models\OutboxMessage;

/**
 * Драйвер отправки сообщения из outbox во внешнюю систему (по одному на target).
 *
 * Узкий интерфейс: отправить или бросить исключение – сам outbox (статус, попытки,
 * next_attempt_at) ведет команда отправки. При слиянии с командой `outbox:send`
 * интерфейсы надо свести к одному (см. PLAN.md, этап 4).
 */
interface Sender
{
    /**
     * Отправить сообщение. Возвращает ключ записи во внешней системе (external_ref)
     * или null, если его нет. Не ушло – исключение с текстом для outbox.error.
     */
    public function send(OutboxMessage $message): ?string;
}
