<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Сотрудник. Зеркало HR, правится только синхронизацией. */
class Employee extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['hired_at' => 'date', 'fired_at' => 'date', 'synced_at' => 'datetime'];
    }

    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class);
    }

    /** За что отвечает как МОЛ. */
    public function molAssets(): HasMany
    {
        return $this->hasMany(Asset::class, 'mol_id');
    }

    /** На чем работает. */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function scopeWorking(Builder $query): Builder
    {
        return $query->whereNull('fired_at');
    }

    public function isFired(): bool
    {
        return $this->fired_at !== null;
    }
}
