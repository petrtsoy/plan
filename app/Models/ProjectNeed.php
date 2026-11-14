<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Что нужно разместить по проекту: категория и количество, модель или габариты – если известны. */
class ProjectNeed extends Model
{
    protected $guarded = [];

    protected $attributes = ['quantity' => 1];

    protected function casts(): array
    {
        return ['requirements' => 'array', 'area_m2' => 'decimal:2'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'model_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LayoutItem::class, 'need_id');
    }

    /** Требования к помещению: свои или модели. */
    public function effectiveRequirements(): array
    {
        return $this->requirements ?? $this->model?->requirements ?? $this->asset?->model?->requirements ?? [];
    }
}
