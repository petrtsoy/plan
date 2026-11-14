<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Категория учета (УЗИ-аппараты, анализаторы, столы, огнетушители). Цвет и значок – свои или слоя. */
class Category extends Model
{
    use Auditable;

    public string $auditType = 'category';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_medical' => 'boolean', 'is_measuring' => 'boolean', 'is_it' => 'boolean'];
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function models(): HasMany
    {
        return $this->hasMany(AssetModel::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function getDisplayColorAttribute(): string
    {
        return $this->color ?: $this->layer->color;
    }
}
