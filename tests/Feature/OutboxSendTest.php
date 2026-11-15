<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AssetTransfer;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Outbox\Driver;
use App\Services\Outbox\OnecDriver;
use App\Services\OutboxSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** outbox:send: повторы с растущей паузой, предел попыток, повтор вручную. */
class OutboxSendTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $target = 'onec'): OutboxMessage
    {
        $transfer = AssetTransfer::create([
            'number' => 'П-2026-00001',
            'transfer_date' => today(),
            'status' => AssetTransfer::ISSUED,
            'issued_at' => now(),
        ]);

        return $transfer->outbox()->create(['target' => $target, 'action' => 'transfer', 'payload' => ['number' => $transfer->number]]);
    }

    /** Драйвер-заглушка: отправляет, падает или говорит, что не настроен. */
    private function driver(bool $configured, ?\Throwable $fail = null, ?string $ref = null): Driver
    {
        return new class($configured, $fail, $ref) implements Driver
        {
            public int $calls = 0;

            public function __construct(private bool $configured, private ?\Throwable $fail, private ?string $ref) {}

            public function configured(): bool
            {
                return $this->configured;
            }

            public function reason(): string
            {
                return 'тестовый драйвер не настроен';
            }

            public function send(OutboxMessage $message): ?string
            {
                $this->calls++;
                if ($this->fail) {
                    throw $this->fail;
                }

                return $this->ref;
            }
        };
    }

    private function using(Driver $driver): Driver
    {
        $this->app->bind(OnecDriver::class, fn () => $driver);

        return $driver;
    }

    /**
     * Ненастроенная система – не сбой доставки: попытки не тратятся, сообщение ждет,
     * причина видна. Иначе после настройки 1С все пришлось бы повторять руками.
     */
    public function test_unconfigured_target_does_not_spend_attempts(): void
    {
        $message = $this->message();

        // Заглушки 1С и it.his.kz пока не настроены – проверяем на них же, без подмены.
        $this->assertSame(['sent' => 0, 'retry' => 0, 'failed' => 0, 'waiting' => 1], app(OutboxSender::class)->run());

        $message->refresh();
        $this->assertSame('pending', $message->status);
        $this->assertSame(0, $message->attempts);
        $this->assertStringContainsString('клиент 1С не настроен', $message->error);
        $this->assertTrue($message->next_attempt_at->greaterThan(now()));

        // И до срока сообщение больше не берется.
        $this->assertSame(0, OutboxMessage::ready()->count());
    }

    public function test_successful_send_marks_message_sent(): void
    {
        $message = $this->message();
        $driver = $this->using($this->driver(configured: true, ref: 'onec-ref-1'));

        $this->assertSame('sent', app(OutboxSender::class)->send($message));

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('onec-ref-1', $message->external_ref);
        $this->assertNotNull($message->sent_at);
        $this->assertNull($message->next_attempt_at);
        $this->assertSame(1, $driver->calls);
        $this->assertSame(0, OutboxMessage::ready()->count());
    }

    /** Неудача – пауза вдвое длиннее прежней, после MAX_ATTEMPTS попыток сообщение failed. */
    public function test_failures_repeat_with_growing_pause_then_give_up(): void
    {
        $message = $this->message();
        $driver = $this->using($this->driver(configured: true, fail: new \RuntimeException('1С не ответила')));
        $sender = app(OutboxSender::class);

        $this->assertSame('retry', $sender->send($message));
        $message->refresh();
        $this->assertSame(1, $message->attempts);
        $this->assertSame('pending', $message->status);
        $this->assertStringContainsString('1С не ответила', $message->error);

        // Пауза еще не вышла – очередь сообщение не берет.
        $this->assertSame(0, OutboxMessage::ready()->count());
        $first = $message->next_attempt_at->diffInMinutes(now(), absolute: true);
        $this->assertSame(OutboxSender::RETRY_MINUTES, (int) round($first));

        $this->travel(OutboxSender::RETRY_MINUTES + 1)->minutes();
        $this->assertSame(['sent' => 0, 'retry' => 1, 'failed' => 0, 'waiting' => 0], $sender->run());
        $message->refresh();
        $this->assertSame(2, $message->attempts);
        $this->assertSame(2 * OutboxSender::RETRY_MINUTES, (int) round($message->next_attempt_at->diffInMinutes(now(), absolute: true)));

        // Остаток попыток – пауза каждый раз выходит.
        while ($message->attempts < OutboxMessage::MAX_ATTEMPTS - 1) {
            $this->travel(OutboxSender::RETRY_MAX_MINUTES + 1)->minutes();
            $sender->run();
            $message->refresh();
        }
        $this->assertSame('pending', $message->status);

        $this->travel(OutboxSender::RETRY_MAX_MINUTES + 1)->minutes();
        $this->assertSame(['sent' => 0, 'retry' => 0, 'failed' => 1, 'waiting' => 0], $sender->run());
        $message->refresh();
        $this->assertSame(OutboxMessage::MAX_ATTEMPTS, $message->attempts);
        $this->assertSame('failed', $message->status);
        $this->assertNull($message->next_attempt_at);
        $this->assertSame(OutboxMessage::MAX_ATTEMPTS, $driver->calls);

        // Failed сама по себе больше не повторяется – только человеком.
        $this->travel(2)->days();
        $this->assertSame(0, OutboxMessage::ready()->count());
    }

    /** Страница "что не ушло": кнопка "Повторить" возвращает сообщение в очередь. */
    public function test_admin_retries_failed_message_by_hand(): void
    {
        $message = $this->message();
        $message->update(['status' => 'failed', 'attempts' => OutboxMessage::MAX_ATTEMPTS, 'error' => '1С не ответила']);

        $this->actingAs(User::factory()->role(Role::Engineer)->create());
        $this->get('/admin/outbox')->assertForbidden();
        $this->post("/admin/outbox/{$message->id}/retry")->assertForbidden();

        $this->actingAs(User::factory()->role(Role::Admin)->create());
        $this->get('/admin/outbox')->assertOk()->assertSee('1С не ответила')->assertSee('не ушло');

        $this->post("/admin/outbox/{$message->id}/retry")->assertRedirect();
        $message->refresh();
        $this->assertSame('pending', $message->status);
        $this->assertSame(0, $message->attempts);
        $this->assertNull($message->next_attempt_at);
        $this->assertSame(1, OutboxMessage::ready()->count());
    }

    public function test_command_reports_what_it_did(): void
    {
        $this->message();

        $this->artisan('outbox:send')->assertSuccessful()->expectsOutputToContain('ждет настройки 1');
    }
}
