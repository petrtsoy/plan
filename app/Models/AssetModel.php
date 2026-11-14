<?php

namespace App\Models;

use App\Enums\RiskClass;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель оборудования из справочника: габариты и сервисные зоны – для плана и примерки,
 * требования к помещению (Specs) и регламент ТО – для новых экземпляров.
 */
class AssetModel extends Model
{
    use Auditable;

    public string $auditType = 'asset_model';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'clearance' => 'array',
            'requirements' => 'array',
            'intervals' => 'array',
            'risk_class' => RiskClass::class,
            'weight_kg' => 'decimal:1',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'model_id');
    }

    public function getTitleAttribute(): string
    {
        return trim(($this->manufacturer ? $this->manufacturer.' ' : '').$this->name);
    }
}
