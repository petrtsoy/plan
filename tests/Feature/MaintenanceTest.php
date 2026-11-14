<?php

namespace Tests\Feature;

use App\Enums\MaintenanceKind;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Layer;
use App\Models\MaintenancePlan;
use App\Services\Maintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function asset(): Asset
    {
        $room = $this->room();
        $category = Category::create(['layer_id' => Layer::create(['code' => 'lab', 'name' => 'Лаборатория'])->id, 'name' => 'Анализаторы', 'is_measuring' => true]);
        $model = AssetModel::create(['category_id' => $category->id, 'name' => 'XN-1000', 'intervals' => ['service' => 6, 'verification' => 12, 'nonsense' => 3]]);

        return Asset::create(['category_id' => $category->id, 'model_id' => $model->id, 'office_id' => $room->floor->building->office_id]);
    }

    public function test_plans_from_model_intervals(): void
    {
        $asset = $this->asset();
        $svc = app(Maintenance::class);

        $this->assertSame(2, $svc->planFromModel($asset));
        $this->assertSame(0, $svc->planFromModel($asset));
        $this->assertSame(12, MaintenancePlan::where('kind', 'verification')->value('interval_months'));
    }

    public function test_record_moves_due_date_and_failed_does_not(): void
    {
        $asset = $this->asset();
        $svc = app(Maintenance::class);
        $svc->planFromModel($asset);

        $svc->record($asset, MaintenanceKind::Verification, ['performed_at' => '2026-03-10', 'document_number' => 'СП-123']);
        $plan = MaintenancePlan::where('kind', 'verification')->sole();
        $this->assertSame('2027-03-10', $plan->next_due_at->toDateString());

        // Свидетельство с явным сроком – берем его.
        $svc->record($asset, MaintenanceKind::Verification, ['performed_at' => '2026-04-01', 'valid_until' => '2027-01-31']);
        $this->assertSame('2027-01-31', $plan->fresh()->next_due_at->toDateString());

        // Не годно – срок не продлевается.
        $svc->record($asset, MaintenanceKind::Verification, ['performed_at' => '2026-05-01', 'result' => 'failed']);
        $this->assertSame('2027-01-31', $plan->fresh()->next_due_at->toDateString());
        $this->assertSame(3, $asset->maintenanceRecords()->count());
    }

    public function test_due_within_scope(): void
    {
        $asset = $this->asset();
        MaintenancePlan::create(['asset_id' => $asset->id, 'kind' => 'service', 'interval_months' => 6, 'next_due_at' => today()->addDays(10)]);
        MaintenancePlan::create(['asset_id' => $asset->id, 'kind' => 'verification', 'interval_months' => 12, 'next_due_at' => today()->addDays(90)]);

        $this->assertSame(1, MaintenancePlan::dueWithin(30)->count());
    }
}
