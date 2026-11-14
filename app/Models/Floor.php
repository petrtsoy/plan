<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Этаж: на нем подложка, помещения, зоны и все, что расставлено (координаты – в метрах этажа). */
class Floor extends Model
{
    use Auditable;

    public string $auditType = 'floor';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['height_m' => 'decimal:2', 'elevation_m' => 'decimal:2'];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->latest('id');
    }

    public function plan(): HasOne
    {
        return $this->hasOne(Plan::class)->where('is_current', true)->latestOfMany();
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('number');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function layouts(): HasMany
    {
        return $this->hasMany(Layout::class)->latest('id');
    }

    /** "Корпус А / 2 этаж". */
    public function getPathAttribute(): string
    {
        return $this->building->name.' / '.$this->name;
    }
}
