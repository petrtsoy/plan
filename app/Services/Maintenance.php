<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceResult;
use App\Models\Asset;
use App\Models\Contractor;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceRecord;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Состояние срока – те же пороги, что раскрашивают единицу на плане этажа:
     * просрочено / скоро (в пределах services.alerts.maintenance_days) / в порядке / нет срока.
     */
    public static function state(mixed $due, ?int $days = null): string
    {
        $days ??= (int) config('services.alerts.maintenance_days');

        if (! $due) {
            return 'none';
        }
        $due = $due instanceof \DateTimeInterface ? Carbon::instance($due) : Carbon::parse($due);

        return match (true) {
            $due->lt(today()) => 'overdue',
            $due->lte(today()->addDays($days)) => 'soon',
            default => 'ok',
        };
    }

    public const STATES = [
        'overdue' => 'Просрочено',
        'soon' => 'Скоро',
        'ok' => 'В порядке',
        'none' => 'Нет регламента',
    ];

    /**
     * Единицы для списка "что пора обслуживать": к каждой – ближайший срок по действующему
     * регламенту (due_at) и сколько таких регламентов. Фильтры: филиал, сервисная организация,
     * слой, вид работ, состояние, поиск по названию и инвентарному номеру.
     */
    public function dueList(array $filters = [], ?int $days = null): Builder
    {
        $days ??= (int) config('services.alerts.maintenance_days');

        $query = Asset::query()
            ->select('assets.*')
            ->where('status', '!=', AssetStatus::WrittenOff)
            ->addSelect(['due_at' => MaintenancePlan::query()->selectRaw('min(next_due_at)')
                ->whereColumn('asset_id', 'assets.id')->where('is_active', true)])
            ->withCount(['maintenancePlans as plans_count' => fn ($q) => $q->where('is_active', true)]);

        if ($office = $filters['office_id'] ?? null) {
            $query->where('office_id', $office);
        }
        if ($layer = $filters['layer_id'] ?? null) {
            $query->whereHas('category', fn ($q) => $q->where('layer_id', $layer));
        }
        if ($contractor = $filters['contractor_id'] ?? null) {
            // Организация бывает задана и в регламенте, и в карточке единицы.
            $query->where(fn ($q) => $q->where('contractor_id', $contractor)
                ->orWhereHas('maintenancePlans', fn ($p) => $p->where('is_active', true)->where('contractor_id', $contractor)));
        }
        if ($kind = $filters['kind'] ?? null) {
            $query->whereHas('maintenancePlans', fn ($q) => $q->where('is_active', true)->where('kind', $kind));
        }
        if ($text = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$text}%")->orWhere('inventory_number', 'like', "%{$text}%"));
        }

        return $this->whereState($query, $filters['state'] ?? null, $days);
    }

    /** Сколько единиц в каждом состоянии при этих фильтрах (без фильтра по состоянию). */
    public function dueCounts(array $filters = [], ?int $days = null): array
    {
        $filters['state'] = null;
        $counts = [];
        foreach (array_keys(self::STATES) as $state) {
            $counts[$state] = $this->whereState($this->dueList($filters, $days), $state, $days)->count();
        }

        return $counts;
    }

    /**
     * Сводка сигналов на день: просроченные и близкие сроки, кончающаяся гарантия,
     * истекающие договоры сервисных организаций. Пустая – письмо не отправляется.
     */
    public function digest(?int $days = null, ?int $warrantyDays = null): array
    {
        $days ??= (int) config('services.alerts.maintenance_days');
        $warrantyDays ??= (int) config('services.alerts.warranty_days');

        $plans = fn (string $state) => MaintenancePlan::query()
            ->with(['asset.office', 'asset.room', 'asset.floor.building', 'contractor'])
            ->where('is_active', true)
            ->whereNotNull('next_due_at')
            ->whereHas('asset', fn ($q) => $q->where('status', '!=', AssetStatus::WrittenOff))
            ->when($state === 'overdue',
                fn ($q) => $q->where('next_due_at', '<', today()),
                fn ($q) => $q->whereBetween('next_due_at', [today(), today()->addDays($days)]))
            ->orderBy('next_due_at')
            ->get();

        $summary = [
            'days' => $days,
            'warranty_days' => $warrantyDays,
            'overdue' => $plans('overdue'),
            'soon' => $plans('soon'),
            'warranty' => Asset::query()
                ->with(['office', 'room'])
                ->where('status', '!=', AssetStatus::WrittenOff)
                ->whereNotNull('warranty_until')
                ->whereBetween('warranty_until', [today(), today()->addDays($warrantyDays)])
                ->orderBy('warranty_until')->get(),
            'contracts' => Contractor::expiringWithin($warrantyDays)->orderBy('contract_until')->get(),
        ];
        $summary['total'] = $summary['overdue']->count() + $summary['soon']->count()
            + $summary['warranty']->count() + $summary['contracts']->count();

        return $summary;
    }

    private function whereState(Builder $query, ?string $state, int $days): Builder
    {
        $due = MaintenancePlan::query()->selectRaw('min(next_due_at)')
            ->whereColumn('asset_id', 'assets.id')->where('is_active', true);

        return match ($state) {
            'overdue' => $query->whereRaw('('.$due->toSql().') < ?', [...$due->getBindings(), today()->toDateString()]),
            'soon' => $query->whereRaw('('.$due->toSql().') between ? and ?', [...$due->getBindings(), today()->toDateString(), today()->addDays($days)->toDateString()]),
            'ok' => $query->whereRaw('('.$due->toSql().') > ?', [...$due->getBindings(), today()->addDays($days)->toDateString()]),
            'none' => $query->whereRaw('('.$due->toSql().') is null', $due->getBindings()),
            default => $query,
        };
    }
}
