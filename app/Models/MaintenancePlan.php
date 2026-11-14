<?php

namespace App\Models;

use App\Enums\MaintenanceKind;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Что и как часто положено делать с единицей; next_due_at – срок, по нему сигнал и цвет на плане. */
class MaintenancePlan extends Model
{
    use Auditable;

    public string $auditType = 'maintenance_plan';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'kind' => MaintenanceKind::class,
            'last_done_at' => 'date',
            'next_due_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class, 'plan_id');
    }

    /** Действующие со сроком не позже чем через $days дней (и просроченные). */
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->where('is_active', true)->whereNotNull('next_due_at')->where('next_due_at', '<=', today()->addDays($days));
    }
}
