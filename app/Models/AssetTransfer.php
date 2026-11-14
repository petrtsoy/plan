<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Акт передачи: одно направление (филиал и МОЛ откуда – куда), строки – единицы учета.
 * Черновик собирает Transfers::attach(), оформляет Transfers::issue() – тогда уходит в 1С.
 */
class AssetTransfer extends Model
{
    use Auditable;

    public string $auditType = 'transfer';

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['status' => self::DRAFT, 'origin' => 'plan'];

    protected function casts(): array
    {
        return ['transfer_date' => 'date', 'issued_at' => 'datetime', 'onec_posted' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssetTransferItem::class, 'transfer_id');
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outbox(): MorphMany
    {
        return $this->morphMany(OutboxMessage::class, 'subject');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
