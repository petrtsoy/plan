<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Позиция варианта: существующая единица (переставить) или модель из справочника (купить). */
class LayoutItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'rotation' => 'float'];
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(Layout::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'model_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** Модель позиции: своя или существующей единицы – по ней габариты и требования. */
    public function effectiveModel(): ?AssetModel
    {
        return $this->model ?? $this->asset?->model;
    }
}
