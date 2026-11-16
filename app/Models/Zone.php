<?php

namespace App\Models;

use App\Enums\ZoneKind;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Область на этаже поверх помещений: отдел, чистая зона, радиационный контроль... */
class Zone extends Model
{
    use Auditable;
    use SoftDeletes;

    public string $auditType = 'zone';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['kind' => ZoneKind::class, 'polygon' => 'array'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** Цвет по умолчанию для типа зоны: свой цвет зоны главнее. */
    public static function colorFor(ZoneKind $kind): string
    {
        return match ($kind) {
            ZoneKind::Department => '#6366f1',
            ZoneKind::Clean => '#0ea5e9',
            ZoneKind::Dirty => '#a16207',
            ZoneKind::Radiation => '#f59e0b',
            ZoneKind::Magnetic => '#7c3aed',
            ZoneKind::Pcr => '#0d9488',
            ZoneKind::Restricted => '#dc2626',
            ZoneKind::Evacuation => '#16a34a',
            ZoneKind::Other => '#64748b',
        };
    }

    public function getDisplayColorAttribute(): string
    {
        return $this->color ?: self::colorFor($this->kind);
    }
}
