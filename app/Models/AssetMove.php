<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Запись истории передач: откуда и куда (филиал, помещение, МОЛ, пользователь). Пишет AssetMover. */
class AssetMove extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['moved_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function fromOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'from_office_id');
    }

    public function toOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'to_office_id');
    }

    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'from_room_id')->withTrashed();
    }

    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'to_room_id')->withTrashed();
    }

    public function fromMol(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_mol_id');
    }

    public function toMol(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_mol_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outbox(): MorphMany
    {
        return $this->morphMany(OutboxMessage::class, 'subject');
    }
}
