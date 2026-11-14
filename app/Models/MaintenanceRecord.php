<?php

namespace App\Models;

use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceResult;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Выполненная работа: ТО, поверка и т.д. с номером акта или свидетельства. Заводится через Maintenance::record(). */
class MaintenanceRecord extends Model
{
    use Auditable;

    public string $auditType = 'maintenance';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['result' => 'ok'];

    protected function casts(): array
    {
        return [
            'kind' => MaintenanceKind::class,
            'result' => MaintenanceResult::class,
            'performed_at' => 'date',
            'valid_until' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'plan_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
