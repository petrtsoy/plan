<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Здание (корпус) филиала. Здание-кандидат проекта (is_candidate) – еще не наше:
 * в списках филиала его нет, настоящие единицы туда не ставятся (только варианты проекта).
 */
class Building extends Model
{
    use Auditable;

    public string $auditType = 'building';

    protected $guarded = [];

    protected $attributes = ['is_candidate' => false];

    protected function casts(): array
    {
        return ['is_candidate' => 'boolean', 'total_area_m2' => 'decimal:2', 'price' => 'decimal:2'];
    }

    /** Настоящие здания, без кандидатов проектов. */
    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_candidate', false);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class)->orderBy('level');
    }
}
