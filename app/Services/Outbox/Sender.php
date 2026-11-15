<?php

namespace App\Services\Outbox;

use App\Models\OutboxMessage;

/**
 * Драйвер отправки сообщения из outbox во внешнюю систему (по одному на target).
 *
 * Узкий интерфейс: отправить или бросить исключение – сам outbox (статус, попытки,
 * next_attempt_at) ведет OutboxSender. Отдельно спрашиваем, настроен ли драйвер:
 * пока клиента нет или не заданы реквизиты, попытки на него не тратятся.
 */
interface Sender
{
    /** Готов ли отправлять: есть реквизиты системы и сделан сам клиент. */
    public function configured(): bool;

    /** Почему не готов – текст для страницы "что не ушло". */
    public function reason(): string;

    /**
     * Отправить сообщение. Возвращает ключ записи во внешней системе (external_ref)
     * или null, если его нет. Не ушло – исключение с текстом для outbox.error.
     */
    public function send(OutboxMessage $message): ?string;
}
