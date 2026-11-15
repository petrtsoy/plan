<?php

namespace App\Services;

use App\Models\OutboxMessage;
use App\Services\Outbox\ItSender;
use App\Services\Outbox\OnecSender;
use App\Services\Outbox\Rejected;
use App\Services\Outbox\Sender;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Отправка того, что накопилось в outbox (команда outbox:send раз в пять минут).
 *
 * Сообщение ставится в одной транзакции с изменением, поэтому перемещение не теряется,
 * даже если 1С недоступна. Неудача – растущая пауза до следующей попытки, после
 * MAX_ATTEMPTS попыток сообщение становится failed и ждет человека (страница "что не ушло",
 * кнопка "Повторить"); отказ, который повторами не исправить (Outbox\Rejected), – failed
 * с первого раза. Куда именно отправлять, знает драйвер target (onec, it).
 */
class OutboxSender
{
    /** Драйвер на каждый target outbox. */
    public const DRIVERS = ['onec' => OnecSender::class, 'it' => ItSender::class];

    /** Пауза перед повтором: 5 минут, дальше вдвое, но не больше 6 часов. */
    public const RETRY_MINUTES = 5;

    public const RETRY_MAX_MINUTES = 360;

    /** Внешняя система не настроена – заглянуть снова через столько минут. */
    public const IDLE_MINUTES = 30;

    /** Pending дольше этого – залежалось, показываем на странице "что не ушло". */
    public const STALE_MINUTES = 60;

    public function __construct(private Container $container) {}

    /**
     * Отправить все, чему пришел срок.
     *
     * @return array{sent: int, retry: int, failed: int, waiting: int}
     */
    public function run(?int $limit = null): array
    {
        $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'waiting' => 0];
        $query = OutboxMessage::ready()->orderBy('id');

        foreach (($limit ? $query->limit($limit) : $query)->get() as $message) {
            $stats[$this->send($message)]++;
        }

        return $stats;
    }

    /**
     * Отправить одно сообщение.
     *
     * @return 'sent'|'retry'|'failed'|'waiting'
     */
    public function send(OutboxMessage $message): string
    {
        $driver = $this->driver($message->target);

        // Ненастроенная внешняя система – не сбой доставки, а незаконченная настройка:
        // попытку на нее не тратим. Иначе за час проверок все сообщения уйдут в failed,
        // и после настройки 1С каждое пришлось бы повторять руками. Просто смотрим позже.
        if (! $driver || ! $driver->configured()) {
            $message->update([
                'error' => $driver ? $driver->reason() : "неизвестный получатель {$message->target}",
                'next_attempt_at' => now()->addMinutes(self::IDLE_MINUTES),
            ]);

            return 'waiting';
        }

        try {
            $ref = $driver->send($message);
        } catch (\Throwable $e) {
            return $this->failed($message, $e);
        }

        $message->update([
            'status' => 'sent',
            'sent_at' => now(),
            'external_ref' => $ref,
            'error' => null,
            'next_attempt_at' => null,
            'attempts' => $message->attempts + 1,
        ]);

        return 'sent';
    }

    /** Повторить руками (страница "что не ушло"): попытки с начала, срок – сейчас. */
    public function retry(OutboxMessage $message): void
    {
        $was = $message->status;
        $message->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null]);
        Audit::log('outbox', $message->id, 'updated', ['status' => [$was, 'pending']], 'Повтор отправки вручную');
    }

    /** Что не ушло: failed и залежавшееся pending – с причиной и числом попыток. */
    public function stuck(int $perPage = 50): LengthAwarePaginator
    {
        return OutboxMessage::with('subject')
            ->where(fn ($q) => $q->where('status', 'failed')
                ->orWhere(fn ($p) => $p->where('status', 'pending')
                    ->where(fn ($s) => $s->where('attempts', '>', 0)
                        ->orWhere('created_at', '<=', now()->subMinutes(self::STALE_MINUTES)))))
            ->latest('id')
            ->paginate($perPage);
    }

    private function failed(OutboxMessage $message, \Throwable $e): string
    {
        $attempts = $message->attempts + 1;
        // Отказ внешней системы (устройства нет, тело не прошло проверку) повторами не
        // вылечить: сразу к человеку, не занимая очередь на сутки вперед.
        $spent = $attempts >= OutboxMessage::MAX_ATTEMPTS || $e instanceof Rejected;

        $message->update([
            'attempts' => $attempts,
            'status' => $spent ? 'failed' : 'pending',
            'error' => Str::limit($e->getMessage(), 500),
            'next_attempt_at' => $spent ? null : now()->addMinutes($this->pause($attempts)),
        ]);

        Log::warning(sprintf('outbox #%d (%s %s): попытка %d из %d – %s', $message->id, $message->target,
            $message->action, $attempts, OutboxMessage::MAX_ATTEMPTS, $e->getMessage()));

        return $spent ? 'failed' : 'retry';
    }

    private function pause(int $attempts): int
    {
        return (int) min(self::RETRY_MINUTES * 2 ** ($attempts - 1), self::RETRY_MAX_MINUTES);
    }

    private function driver(string $target): ?Sender
    {
        $class = self::DRIVERS[$target] ?? null;

        return $class ? $this->container->make($class) : null;
    }
}
