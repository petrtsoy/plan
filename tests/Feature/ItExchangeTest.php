<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetMove;
use App\Models\AssetTransfer;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Layer;
use App\Models\OutboxMessage;
use App\Models\Room;
use App\Models\User;
use App\Services\AssetMover;
use App\Services\Outbox\ItSender;
use App\Services\Outbox\Rejected;
use App\Services\OutboxSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Обмен с it.his.kz: прием событий перемещений и отправка туда (docs/it-exchange.md). */
class ItExchangeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'secret-key-from-settings';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.it.incoming_key' => self::KEY, 'services.it.base_url' => 'https://it.test', 'services.it.api_key' => 'to-it-key', 'services.it.timeout' => 30]);
    }

    private function asset(Room $room, array $attrs = []): Asset
    {
        $layer = Layer::firstOrCreate(['code' => 'it'], ['name' => 'ИТ']);
        $category = Category::firstOrCreate(['layer_id' => $layer->id, 'name' => 'Компьютеры'], ['is_it' => true]);

        return Asset::create($attrs + [
            'category_id' => $category->id,
            'name' => 'Моноблок',
            'inventory_number' => 'INV-1',
            'office_id' => $room->floor->building->office_id,
            'room_id' => $room->id,
            'floor_id' => $room->floor_id,
            'x' => 1, 'y' => 1,
        ]);
    }

    /** Событие: обязательные поля и кабинет, куда переместили. */
    private function event(array $event = []): array
    {
        return $event + [
            'move_id' => 501,
            'device_id' => 77,
            'inv_number' => 'INV-1',
            'office_hr_id' => 1,
            'location_id' => 340,
            'employee_hr_id' => null,
            'moved_at' => '2026-11-10T08:30:00Z',
            'moved_by' => ['iin' => '860101300123', 'name' => 'Петров П.П.'],
            'comment' => 'Переставили в другой кабинет',
        ];
    }

    private function deliver(array $event, ?string $key = self::KEY)
    {
        return $this->postJson('/api/it/moves', $event, $key === null ? [] : ['X-Api-Key' => $key]);
    }

    public function test_event_moves_the_asset_and_goes_only_to_the_act(): void
    {
        $from = $this->room(1);
        $to = Room::create(['floor_id' => $from->floor_id, 'number' => '102', 'name' => 'Серверная', 'it_location_id' => 340]);
        $asset = $this->asset($from, ['it_device_id' => 77]);
        $user = User::create(['iin' => '860101300123', 'name' => 'Петров П.П.', 'role' => 'engineer']);
        $employee = Employee::create(['hr_id' => 55, 'full_name' => 'Иванов И.И.']);

        $response = $this->deliver($this->event(['employee_hr_id' => 55]))->assertOk();
        $response->assertJson(['status' => 'success', 'duplicate' => false, 'asset_id' => $asset->id, 'warnings' => []]);

        $asset->refresh();
        $this->assertSame($to->id, $asset->room_id);
        $this->assertSame($employee->id, $asset->employee_id);

        $move = AssetMove::sole();
        $this->assertSame('it', $move->origin);
        $this->assertSame(501, $move->it_move_id);
        $this->assertSame($user->id, $move->user_id);
        $this->assertSame('2026-11-10 08:30:00', $move->moved_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Переставили в другой кабинет (Петров П.П.)', $move->comment);

        // Внутри филиала МОЛ не менялся – акта нет; обратно в it.his.kz не отправляем.
        $this->assertSame(0, AssetTransfer::count());
        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_unknown_device_is_matched_by_inventory_number_and_link_is_remembered(): void
    {
        $from = $this->room(1);
        Room::create(['floor_id' => $from->floor_id, 'number' => '102', 'name' => 'Серверная', 'it_location_id' => 340]);
        $asset = $this->asset($from);

        $this->deliver($this->event())->assertOk()->assertJson(['asset_id' => $asset->id]);

        $this->assertSame(77, $asset->fresh()->it_device_id);
    }

    public function test_repeated_delivery_does_not_create_a_second_move(): void
    {
        $from = $this->room(1);
        Room::create(['floor_id' => $from->floor_id, 'number' => '102', 'name' => 'Серверная', 'it_location_id' => 340]);
        $this->asset($from, ['it_device_id' => 77]);

        $first = $this->deliver($this->event())->assertOk();
        $again = $this->deliver($this->event())->assertOk();

        $again->assertJson(['status' => 'success', 'duplicate' => true, 'move_id' => $first->json('move_id')]);
        $this->assertSame(1, AssetMove::count());
    }

    public function test_unknown_device_gets_a_clear_answer_and_a_journal_record(): void
    {
        $this->room(1);

        $this->deliver($this->event(['device_id' => 999, 'inv_number' => 'INV-НЕТ']))
            ->assertNotFound()
            ->assertJson(['status' => 'error', 'error' => 'device_not_found'])
            ->assertJsonFragment(['message' => 'Устройство не найдено: ни по связке, ни по инвентарному номеру. Нужно завести единицу учета в plan или проверить инвентарный номер.']);

        $log = AuditLog::where('subject_type', 'it_exchange')->sole();
        $this->assertSame('device_not_found', $log->action);
        $this->assertSame(999, $log->changes['device_id']);
        $this->assertSame(0, AssetMove::count());
    }

    public function test_key_is_required(): void
    {
        $this->deliver($this->event(), key: null)->assertUnauthorized()->assertJson(['error' => 'unauthorized']);
        $this->deliver($this->event(), key: 'не тот')->assertUnauthorized();

        // Ключ не задан в настройках – прием выключен даже с пустым заголовком.
        config(['services.it.incoming_key' => null]);
        $this->deliver($this->event(), key: '')->assertUnauthorized();
    }

    public function test_unmatched_room_leaves_the_asset_unplaced_in_the_branch(): void
    {
        $room = $this->room(1);
        $asset = $this->asset($room);

        $this->deliver($this->event(['location_id' => 7777]))->assertOk()
            ->assertJson(['status' => 'success', 'warnings' => ['room_not_matched']]);

        $asset->refresh();
        $this->assertNull($asset->room_id);
        $this->assertNull($asset->floor_id);
        $this->assertSame($room->floor->building->office_id, $asset->office_id);
        $this->assertSame('move_received', AuditLog::where('subject_type', 'it_exchange')->sole()->action);
    }

    public function test_unknown_branch_is_an_error(): void
    {
        $room = $this->room(1);
        $this->asset($room);

        $this->deliver($this->event(['office_hr_id' => 42]))
            ->assertStatus(422)
            ->assertJson(['status' => 'error', 'error' => 'office_not_found']);
        $this->assertSame(0, AssetMove::count());
    }

    public function test_move_here_goes_to_outbox_and_the_driver_sends_it(): void
    {
        $karaganda = $this->room(1);
        $astana = $this->room(2);
        $target = Room::create(['floor_id' => $astana->floor_id, 'number' => '201', 'name' => 'Кабинет', 'it_location_id' => 912]);
        $employee = Employee::create(['hr_id' => 55, 'full_name' => 'Иванов И.И.']);
        $asset = $this->asset($karaganda, ['inventory_number' => 'INV-9']);

        app(AssetMover::class)->move($asset, ['room_id' => $target->id, 'employee_id' => $employee->id], 'Перевод сотрудника');

        $message = OutboxMessage::sole();
        $this->assertSame('it', $message->target);
        $this->assertSame('pending', $message->status);

        Http::fake(['it.test/api/plan/moves' => Http::response(['status' => 'success', 'device_id' => 77, 'move_id' => 9012])]);

        $this->assertSame('9012', app(ItSender::class)->send($message));

        Http::assertSent(function (Request $request) use ($employee) {
            $this->assertSame('to-it-key', $request->header('X-Api-Key')[0]);
            $this->assertSame([
                'device_id' => null,
                'inv_number' => 'INV-9',
                'office_hr_id' => 2,
                'location_id' => 912,
                'employee_hr_id' => $employee->hr_id,
                'transfer_number' => AssetTransfer::sole()->number,
                'comment' => 'Перевод сотрудника',
            ], $request->data());

            return $request->url() === 'https://it.test/api/plan/moves';
        });

        // Устройство нашлось там по инвентарному номеру – связка запомнилась.
        $this->assertSame(77, $asset->fresh()->it_device_id);
    }

    public function test_driver_reports_a_refusal_from_it(): void
    {
        $message = $this->moveInside();

        Http::fake(['it.test/*' => Http::response(['status' => 'error', 'error' => 'device_not_found'], 404)]);

        $this->expectException(Rejected::class);
        $this->expectExceptionMessage('it.his.kz ответил 404: device_not_found');
        app(ItSender::class)->send($message);
    }

    /** Замечания it.his.kz (связка кабинета устарела, сотрудника там нет) – в журнал. */
    public function test_warnings_from_it_go_to_the_journal(): void
    {
        $message = $this->moveInside();
        Http::fake(['it.test/*' => Http::response(['status' => 'success', 'move_id' => 9012, 'warnings' => ['room_not_matched']])]);

        $this->assertSame('sent', app(OutboxSender::class)->send($message));

        $log = AuditLog::where('subject_type', 'it_exchange')->sole();
        $this->assertSame('move_sent', $log->action);
        $this->assertSame(['room_not_matched'], $log->changes['warnings']);
        $this->assertSame('9012', $message->fresh()->external_ref);
    }

    /**
     * Чего повторы не исправят (устройства там нет), – сразу к человеку; незаведенный
     * филиал пройдет сам после hr:sync в it.his.kz, поэтому такое сообщение повторяется.
     */
    public function test_refusal_is_not_repeated_but_an_unsynced_branch_is(): void
    {
        $message = $this->moveInside();
        Http::fake(['it.test/*' => Http::sequence()
            ->push(['status' => 'error', 'error' => 'device_not_found'], 404)
            ->push(['status' => 'error', 'error' => 'office_not_found'], 422)]);

        $this->assertSame('failed', app(OutboxSender::class)->send($message));
        $message->refresh();
        $this->assertSame(1, $message->attempts);
        $this->assertNull($message->next_attempt_at);
        $this->assertStringContainsString('device_not_found', $message->error);

        // Филиал заведут синхронизацией – такое сообщение ждет повтора, а не человека.
        app(OutboxSender::class)->retry($message);

        $this->assertSame('retry', app(OutboxSender::class)->send($message));
        $message->refresh();
        $this->assertSame('pending', $message->status);
        $this->assertTrue($message->next_attempt_at->greaterThan(now()));
    }

    /** Перемещение ИТ-единицы внутри филиала: сообщение для it.his.kz, акта нет. */
    private function moveInside(): OutboxMessage
    {
        $room = $this->room(1);
        $other = Room::create(['floor_id' => $room->floor_id, 'number' => '102', 'name' => 'Серверная']);
        app(AssetMover::class)->move($this->asset($room, ['it_device_id' => 77]), ['room_id' => $other->id]);

        return OutboxMessage::sole();
    }
}
