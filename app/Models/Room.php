<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Geometry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Помещение: контур на этаже, назначение, отделение и инженерные свойства (Specs). */
class Room extends Model
{
    use Auditable;

    // Убранное с плана помещение пропадает из списков, но история его еще показывает.
    use SoftDeletes;

    public string $auditType = 'room';

    protected $guarded = [];

    protected static function booted(): void
    {
        // Площадь по контуру, если ее не ввели руками.
        static::saving(function (Room $room) {
            if ($room->polygon && ($room->area_m2 === null || ($room->isDirty('polygon') && ! $room->isDirty('area_m2')))) {
                $room->area_m2 = round(Geometry::area($room->polygon), 2);
            }
        });
    }

    protected function casts(): array
    {
        return ['polygon' => 'array', 'specs' => 'array', 'area_m2' => 'decimal:2', 'label_x' => 'float', 'label_y' => 'float'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** "214 Процедурная". */
    public function getTitleAttribute(): string
    {
        return trim(($this->number ? $this->number.' ' : '').$this->name);
    }
}
