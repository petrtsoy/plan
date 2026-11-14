<?php

namespace App\Models;

use App\Enums\InventoryStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Инвентаризация филиала (или части помещений) с ведомостью. Открывает и закрывает Inventories. */
class Inventory extends Model
{
    use Auditable;

    public string $auditType = 'inventory';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'status' => InventoryStatus::class,
            'order_date' => 'date',
            'scope' => 'array',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
