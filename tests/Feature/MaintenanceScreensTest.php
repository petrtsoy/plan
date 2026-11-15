<?php

namespace Tests\Feature;

use App\Enums\MaintenanceKind;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Contractor;
use App\Models\Layer;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceRecord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Экраны ТО: список "что пора обслуживать", регламент, запись выполнения, сервисные организации. */
class MaintenanceScreensTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private function asset(string $name, ?string $due = null): Asset
    {
        $this->room ??= $this->room();
        $layer = Layer::firstOrCreate(['code' => 'lab'], ['name' => 'Лаборатория']);
        $category = Category::firstOrCreate(['layer_id' => $layer->id, 'name' => 'Анализаторы'], ['is_measuring' => true]);

        $asset = Asset::create([
            'category_id' => $category->id,
            'name' => $name,
            'office_id' => $this->room->floor->building->office_id,
            'floor_id' => $this->room->floor_id,
            'room_id' => $this->room->id,
        ]);
        if ($due !== null) {
            MaintenancePlan::create(['asset_id' => $asset->id, 'kind' => 'verification', 'interval_months' => 12, 'next_due_at' => $due]);
        }

        return $asset;
    }

    public function test_record_through_controller_moves_due_date_and_keeps_certificate(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $asset = $this->asset('Анализатор просроченный', today()->subDays(5)->toDateString());
        $contractor = Contractor::create(['name' => 'КазИнМетр', 'contract_until' => today()->addYear()]);

        $this->post(route('maintenance.records.store', $asset), [
            'kind' => MaintenanceKind::Verification->value,
            'performed_at' => today()->toDateString(),
            'result' => 'ok',
            'contractor_id' => $contractor->id,
            'document_number' => 'СП-2026/17',
            'cost' => '45000',
            'document' => UploadedFile::fake()->create('svidetelstvo.pdf', 120, 'application/pdf'),
        ])->assertRedirect()->assertSessionHas('ok');

        $record = MaintenanceRecord::sole();
        $this->assertSame('СП-2026/17', $record->document_number);
        $this->assertSame($contractor->id, $record->contractor_id);
        // Срок сдвинут на интервал регламента – через Maintenance::record().
        $this->assertSame(today()->addYear()->toDateString(), MaintenancePlan::sole()->next_due_at->toDateString());

        $file = Attachment::sole();
        $this->assertSame('certificate', $file->kind);
        $this->assertTrue($record->attachments->contains($file));
        Storage::disk('local')->assertExists($file->path);
        $this->get(route('files.show', $file))->assertOk();
    }

    public function test_record_needs_kind_and_date_in_the_past(): void
    {
        $this->actingAs(User::factory()->role(Role::Engineer)->create());
        $asset = $this->asset('Анализатор', today()->toDateString());

        $this->post(route('maintenance.records.store', $asset), [
            'kind' => 'nonsense',
            'performed_at' => today()->addDay()->toDateString(),
            'result' => 'ok',
        ])->assertSessionHasErrors(['kind', 'performed_at']);

        $this->assertSame(0, MaintenanceRecord::count());
    }

    public function test_due_list_filters_by_state_and_office(): void
    {
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $overdue = $this->asset('Центрифуга просрочена', today()->subDay()->toDateString());
        $soon = $this->asset('Весы скоро', today()->addDays(10)->toDateString());
        $ok = $this->asset('Термостат в порядке', today()->addYears(2)->toDateString());
        $none = $this->asset('Стол без регламента');

        $this->get(route('maintenance.index'))->assertOk()
            ->assertSee($overdue->name)->assertSee($soon->name)->assertSee($ok->name)->assertSee($none->name);

        $this->get(route('maintenance.index', ['state' => 'overdue']))->assertOk()
            ->assertSee($overdue->name)->assertDontSee($soon->name)->assertDontSee($none->name);

        $this->get(route('maintenance.index', ['state' => 'soon']))->assertOk()
            ->assertSee($soon->name)->assertDontSee($overdue->name);

        $this->get(route('maintenance.index', ['state' => 'none']))->assertOk()
            ->assertSee($none->name)->assertDontSee($ok->name);

        // Другой филиал – в списке ничего не остается.
        $this->get(route('maintenance.index', ['office_id' => $overdue->office_id + 1]))
            ->assertOk()->assertDontSee($overdue->name)->assertDontSee($none->name);
    }

    public function test_plan_is_added_edited_and_not_duplicated(): void
    {
        $this->actingAs(User::factory()->role(Role::Engineer)->create());
        $asset = $this->asset('Дефибриллятор');

        $this->post(route('maintenance.plans.store', $asset), [
            'kind' => MaintenanceKind::Service->value,
            'interval_months' => 6,
            'next_due_at' => today()->addMonths(6)->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $plan = MaintenancePlan::sole();
        $this->assertSame(6, $plan->interval_months);
        $this->assertTrue($plan->is_active);

        // Тот же вид работ второй раз – правка, а не вторая строка (unique asset_id + kind).
        $this->post(route('maintenance.plans.store', $asset), [
            'kind' => MaintenanceKind::Service->value,
            'interval_months' => 12,
        ])->assertRedirect();
        $this->assertSame(1, MaintenancePlan::count());

        $this->put(route('maintenance.plans.update', $plan), [
            'kind' => MaintenanceKind::Service->value,
            'interval_months' => 3,
        ])->assertRedirect()->assertSessionHas('ok');
        $this->assertSame(3, $plan->fresh()->interval_months);
        // Галочку сняли – регламент не действует и в сигналы не идет.
        $this->assertFalse($plan->fresh()->is_active);
    }

    public function test_plan_from_model_intervals_through_controller(): void
    {
        $this->actingAs(User::factory()->role(Role::Engineer)->create());
        $asset = $this->asset('Анализатор с моделью');
        $model = AssetModel::create(['category_id' => $asset->category_id, 'name' => 'XN-1000',
            'intervals' => ['service' => 6, 'verification' => 12]]);
        $asset->update(['model_id' => $model->id]);

        $this->post(route('maintenance.plans.from-model', $asset))->assertRedirect()->assertSessionHas('ok');
        $this->assertSame(2, MaintenancePlan::count());

        // Второй раз добавлять нечего.
        $this->post(route('maintenance.plans.from-model', $asset))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(2, MaintenancePlan::count());
    }

    public function test_contractors_screen_and_expiring_contract_signal(): void
    {
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->post(route('contractors.store'), ['name' => 'Медсервис', 'bin' => '123456789012',
            'contract_number' => 'Д-7', 'contract_until' => today()->addDays(10)->toDateString()])
            ->assertRedirect()->assertSessionHas('ok');

        $contractor = Contractor::sole();
        $this->get(route('contractors.index'))->assertOk()->assertSee('Медсервис')->assertSee('Д-7');

        // Пустое поле формы приходит как null – срок договора снимается.
        $this->put(route('contractors.update', $contractor), ['name' => 'Медсервис плюс', 'contract_until' => ''])
            ->assertRedirect()->assertSessionHas('ok');
        $this->assertSame('Медсервис плюс', $contractor->fresh()->name);
        $this->assertNull($contractor->fresh()->contract_until);

        // Истекающий договор – сигнал на главной.
        $contractor->update(['contract_until' => today()->addDays(5)]);
        $this->get('/')->assertOk()->assertSee('Договоры сервисных организаций истекают');
        $this->assertSame(1, Contractor::expiringWithin((int) config('services.alerts.warranty_days'))->count());
    }

    public function test_only_assets_role_opens_maintenance(): void
    {
        $asset = $this->asset('Анализатор');

        $this->actingAs(User::factory()->role(Role::Viewer)->create());
        $this->get(route('maintenance.index'))->assertForbidden();
        $this->get(route('maintenance.asset', $asset))->assertForbidden();
        $this->get(route('contractors.index'))->assertForbidden();

        $this->actingAs(User::factory()->role(Role::Engineer)->create());
        $this->get(route('maintenance.index'))->assertOk();
        $this->get(route('maintenance.asset', $asset))->assertOk()->assertSee('Записать выполнение');
        $this->get(route('maintenance.records'))->assertOk();
    }
}
