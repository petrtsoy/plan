<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Акт передачи: смена филиала или МОЛ. Создает AssetMover, в 1С и it.his.kz уходит через outbox. */
class AssetTransfer extends Model
{
    use Auditable;

    public string $auditType = 'transfer';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['transfer_date' => 'date', 'onec_posted' => 'boolean'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(AssetMove::class, 'move_id');
    }

    public function fromOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'from_office_id');
    }

    public function toOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'to_office_id');
    }

    public function fromMol(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_mol_id');
    }

    public function toMol(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_mol_id');
    }

    public function outbox(): MorphMany
    {
        return $this->morphMany(OutboxMessage::class, 'subject');
    }
}
