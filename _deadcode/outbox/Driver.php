<?php

namespace App\Services\Outbox;

use App\Models\OutboxMessage;

/**
 * Куда уходит сообщение из outbox: свой драйвер на каждый target (onec, it).
 * Так отправка не зависит от того, готов ли клиент внешней системы: пока его нет,
 * драйвер-заглушка просто говорит, что не настроен, и сообщение ждет в очереди.
 */
interface Driver
{
    /** Готов ли отправлять: есть реквизиты системы и сделан сам клиент. */
    public function configured(): bool;

    /** Почему не готов – текст для страницы "что не ушло". */
    public function reason(): string;

    /**
     * Отправить. Вернуть ссылку созданного во внешней системе объекта (или null, если ее нет).
     * Неудача – исключение: его текст ложится в outbox.error, попытка считается неуспешной.
     */
    public function send(OutboxMessage $message): ?string;
}
