<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Layer;
use App\Models\OutboxMessage;
use App\Models\Room;
use App\Models\User;
use App\Services\AssetMover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Страница актов: черновики, оформление, печать и чьи черновики видно. */
class TransfersTest extends TestCase
{
    use RefreshDatabase;

    private function asset(Room $room, array $attrs = []): Asset
    {
        $layer = Layer::firstOrCreate(['code' => 'lab'], ['name' => 'Лаборатория']);
        $category = Category::firstOrCreate(['layer_id' => $layer->id, 'name' => 'Анализаторы']);

        return Asset::create($attrs + [
            'category_id' => $category->id,
            'name' => 'Анализатор',
            'inventory_number' => 'INV-'.Asset::count(),
            'serial_number' => 'SN-777',
            'office_id' => $room->floor->building->office_id,
            'room_id' => $room->id,
            'floor_id' => $room->floor_id,
        ]);
    }

    /** Передача между филиалами -> черновик; "Оформить" закрывает акт и ставит сообщение в outbox. */
    public function test_draft_is_issued_from_page_and_queued_to_1c(): void
    {
        $from = $this->room(1);
        $to = $this->room(2);
        $user = User::factory()->role(Role::Engineer)->create();
        $this->actingAs($user);

        $asset = $this->asset($from, ['mol_id' => Employee::create(['hr_id' => 21, 'full_name' => 'Сдал С.С.'])->id]);
        app(AssetMover::class)->move($asset, ['room_id' => $to->id]);
        $draft = AssetTransfer::sole();

        $this->get('/transfers')->assertOk()->assertSee($draft->number)->assertSee('Оформить');

        $this->post("/transfers/{$draft->id}/issue", ['reason' => 'Приказ 12'])
            ->assertRedirect(route('transfers.index'));

        $draft->refresh();
        $this->assertSame(AssetTransfer::ISSUED, $draft->status);
        $this->assertNotNull($draft->issued_at);
        $this->assertSame('Приказ 12', $draft->reason);

        $message = OutboxMessage::sole();
        $this->assertSame('onec', $message->target);
        $this->assertSame('pending', $message->status);
        $this->assertTrue($message->subject->is($draft));
        $this->assertSame($draft->number, $message->payload['number']);

        // Оформленный акт – в списке с состоянием отправки, второй раз не оформляется.
        $this->get('/transfers')->assertOk()->assertSee('в очереди в 1С');
        $this->post("/transfers/{$draft->id}/issue")->assertSessionHasErrors('transfer');
        $this->assertSame(1, OutboxMessage::count());
    }

    public function test_print_page_has_header_items_and_signatures(): void
    {
        $from = $this->room(1);
        $to = $this->room(2);
        $user = User::factory()->role(Role::Engineer)->create();
        $this->actingAs($user);

        $asset = $this->asset($from);
        app(AssetMover::class)->move($asset, ['room_id' => $to->id], 'Перераспределение');
        $act = AssetTransfer::sole();

        $this->get("/transfers/{$act->id}/print")->assertOk()
            ->assertSee('Акт приема-передачи имущества № '.$act->number)
            ->assertSee($act->transfer_date->format('d.m.Y'))
            ->assertSee('Филиал 1')
            ->assertSee('Филиал 2')
            ->assertSee('Перераспределение')
            ->assertSee($asset->inventory_number)
            ->assertSee('SN-777')
            ->assertSee('Сдал')
            ->assertSee('Принял');
    }

    /**
     * Черновик из it.his.kz – свой автора у него нет: оформляет вручную тот, кто смотрит
     * страницу. Обычный чужой черновик виден только автору.
     */
    public function test_it_draft_is_visible_to_everyone_with_assets_right(): void
    {
        $from = $this->room(1);
        $to = $this->room(2);
        $author = User::factory()->role(Role::Engineer)->create();
        $other = User::factory()->role(Role::Engineer)->create();

        $this->actingAs($author);
        $mine = $this->asset($from);
        app(AssetMover::class)->move($mine, ['room_id' => $to->id]);
        $own = AssetTransfer::sole();

        $fromIt = $this->asset($from, ['it_device_id' => 5]);
        app(AssetMover::class)->move($fromIt, ['room_id' => $to->id], itMoveId: 900);
        $itDraft = AssetTransfer::where('origin', 'it')->sole();

        $this->actingAs($other)->get('/transfers')->assertOk()
            ->assertSee($itDraft->number)->assertDontSee($own->number);

        $this->post("/transfers/{$itDraft->id}/issue")->assertRedirect(route('transfers.index'));
        $this->assertSame(AssetTransfer::ISSUED, $itDraft->fresh()->status);
    }

    public function test_viewer_has_no_access_to_acts(): void
    {
        $this->actingAs(User::factory()->role(Role::Viewer)->create());
        $this->get('/transfers')->assertForbidden();
    }
}
