<?php

namespace App\Services;

use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceResult;
use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Регламент и выполнение ТО, поверки и прочих периодических работ. */
class Maintenance
{
    /** Планы по регламенту модели (asset_models.intervals) – для новой единицы; уже заведенные не трогает. */
    public function planFromModel(Asset $asset): int
    {
        $n = 0;
        foreach ($asset->model?->intervals ?? [] as $kind => $months) {
            if (! MaintenanceKind::tryFrom($kind) || ! $months) {
                continue;
            }
            $plan = MaintenancePlan::firstOrCreate(
                ['asset_id' => $asset->id, 'kind' => $kind],
                ['interval_months' => (int) $months, 'contractor_id' => $asset->contractor_id],
            );
            $n += (int) $plan->wasRecentlyCreated;
        }

        return $n;
    }

    /**
     * Записать выполнение и сдвинуть срок. "Не годно" срок не продлевает: единица остается
     * красной на плане, пока не будет повторной успешной записи.
     */
    public function record(Asset $asset, MaintenanceKind $kind, array $data): MaintenanceRecord
    {
        return DB::transaction(function () use ($asset, $kind, $data) {
            $plan = MaintenancePlan::where('asset_id', $asset->id)->where('kind', $kind)->first();
            $performed = Carbon::parse($data['performed_at']);
            $result = MaintenanceResult::from($data['result'] ?? MaintenanceResult::Ok->value);

            $validUntil = $data['valid_until'] ?? null;
            if (! $validUntil && $plan && $result !== MaintenanceResult::Failed) {
                $validUntil = $performed->copy()->addMonthsNoOverflow($plan->interval_months)->toDateString();
            }

            $record = MaintenanceRecord::create([
                'asset_id' => $asset->id,
                'plan_id' => $plan?->id,
                'kind' => $kind,
                'performed_at' => $performed->toDateString(),
                'result' => $result,
                'valid_until' => $validUntil,
                'contractor_id' => $data['contractor_id'] ?? $plan?->contractor_id,
                'document_number' => $data['document_number'] ?? null,
                'cost' => $data['cost'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => Auth::id(),
            ]);

            if ($plan && $result !== MaintenanceResult::Failed && (! $plan->last_done_at || $performed->gte($plan->last_done_at))) {
                $plan->update(['last_done_at' => $performed->toDateString(), 'next_due_at' => $validUntil]);
            }

            return $record;
        });
    }
}
