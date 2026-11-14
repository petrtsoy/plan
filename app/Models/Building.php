<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Здание (корпус) филиала. */
class Building extends Model
{
    use Auditable;

    public string $auditType = 'building';

    protected $guarded = [];

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class)->orderBy('level');
    }
}
