<?php

namespace App\Models;

use App\Enums\ZoneKind;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Область на этаже поверх помещений: отдел, чистая зона, радиационный контроль... */
class Zone extends Model
{
    use Auditable;

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
}
