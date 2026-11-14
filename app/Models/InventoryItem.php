<?php

namespace App\Models;

use App\Enums\InventoryMark;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Строка ведомости: что числится, где ожидали и где нашли. */
class InventoryItem extends Model
{
    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['mark' => 'pending'];

    protected function casts(): array
    {
        return ['mark' => InventoryMark::class, 'checked_at' => 'datetime'];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function expectedRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'expected_room_id');
    }

    public function foundRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'found_room_id');
    }

    public function expectedMol(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'expected_mol_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
