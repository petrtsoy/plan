<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Проект: несколько зданий-кандидатов, что нужно разместить и варианты расстановки в
 * каждом. Итог – Projects::choose() (одно здание становится настоящим) или reject().
 */
class Project extends Model
{
    use Auditable;

    public string $auditType = 'project';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['status' => ProjectStatus::class, 'decided_at' => 'datetime'];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** Здания проекта: кандидаты и уже выбранное. */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class)->orderBy('name');
    }

    public function chosenBuilding(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'chosen_building_id');
    }

    public function needs(): HasMany
    {
        return $this->hasMany(ProjectNeed::class);
    }

    public function layouts(): HasMany
    {
        return $this->hasMany(Layout::class);
    }

    public function isOpen(): bool
    {
        return $this->status === ProjectStatus::Open;
    }
}
