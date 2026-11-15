<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Mail\MaintenanceDigest;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Contractor;
use App\Models\Layer;
use App\Models\MaintenancePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Ежедневная сводка сигналов: что попадает в письмо и когда письма нет вовсе. */
class MaintenanceDigestTest extends TestCase
{
    use RefreshDatabase;

    private function asset(string $name, array $attributes = []): Asset
    {
        $room = $this->room(1);
        $layer = Layer::firstOrCreate(['code' => 'med'], ['name' => 'Медоборудование']);
        $category = Category::firstOrCreate(['layer_id' => $layer->id, 'name' => 'Анализаторы'], ['is_medical' => true]);

        return Asset::create([
            'category_id' => $category->id,
            'name' => $name,
            'office_id' => $room->floor->building->office_id,
            'floor_id' => $room->floor_id,
            'room_id' => $room->id,
        ] + $attributes);
    }

    public function test_digest_goes_to_alert_emails_with_overdue_and_soon(): void
    {
        Mail::fake();
        config(['services.alerts.emails' => ['engineer@his.kz', 'chief@his.kz'], 'services.alerts.maintenance_days' => 30]);

        $overdue = $this->asset('Анализатор просроченный');
        MaintenancePlan::create(['asset_id' => $overdue->id, 'kind' => 'verification', 'interval_months' => 12, 'next_due_at' => today()->subDays(3)]);

        $soon = $this->asset('Весы лабораторные');
        MaintenancePlan::create(['asset_id' => $soon->id, 'kind' => 'service', 'interval_months' => 6, 'next_due_at' => today()->addDays(7)]);

        // Срок далеко – в письме ему места нет.
        $later = $this->asset('Термостат спокойный');
        MaintenancePlan::create(['asset_id' => $later->id, 'kind' => 'service', 'interval_months' => 6, 'next_due_at' => today()->addMonths(6)]);

        Artisan::call('plan:maintenance-digest');

        Mail::assertSent(MaintenanceDigest::class, function (MaintenanceDigest $mail) use ($overdue, $soon, $later) {
            $mail->assertSeeInHtml($overdue->name);
            $mail->assertSeeInHtml($soon->name);
            $mail->assertDontSeeInHtml($later->name);

            return $mail->hasTo('engineer@his.kz') && $mail->hasTo('chief@his.kz');
        });
    }

    public function test_empty_digest_is_not_sent(): void
    {
        Mail::fake();
        config(['services.alerts.emails' => ['engineer@his.kz']]);

        // Списанное и далекие сроки сигналом не считаются.
        $writtenOff = $this->asset('Старый анализатор', ['status' => AssetStatus::WrittenOff]);
        MaintenancePlan::create(['asset_id' => $writtenOff->id, 'kind' => 'verification', 'interval_months' => 12, 'next_due_at' => today()->subYear()]);
        Contractor::create(['name' => 'Медсервис', 'contract_until' => today()->addYears(2)]);

        Artisan::call('plan:maintenance-digest');

        Mail::assertNothingSent();
        $this->assertStringContainsString('не отправляем', Artisan::output());
    }

    public function test_digest_without_recipients_is_not_sent(): void
    {
        Mail::fake();
        config(['services.alerts.emails' => []]);

        $asset = $this->asset('Анализатор просроченный');
        MaintenancePlan::create(['asset_id' => $asset->id, 'kind' => 'verification', 'interval_months' => 12, 'next_due_at' => today()->subDay()]);

        Artisan::call('plan:maintenance-digest');

        Mail::assertNothingSent();
    }

    public function test_dry_run_shows_counts_and_sends_nothing(): void
    {
        Mail::fake();
        config(['services.alerts.emails' => ['engineer@his.kz']]);

        $asset = $this->asset('Дефибриллятор');
        MaintenancePlan::create(['asset_id' => $asset->id, 'kind' => 'service', 'interval_months' => 6, 'next_due_at' => today()->subDays(2)]);
        Contractor::create(['name' => 'Медсервис', 'contract_until' => today()->addDays(5)]);

        Artisan::call('plan:maintenance-digest', ['--dry-run' => true]);
        $output = Artisan::output();

        Mail::assertNothingSent();
        $this->assertStringContainsString('Просрочено 1', $output);
        $this->assertStringContainsString('dry-run', $output);
    }
}
