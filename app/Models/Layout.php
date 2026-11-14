<?php

namespace App\Models;

use App\Enums\LayoutStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Вариант расстановки на этаже: примерка до покупки или переезда. */
class Layout extends Model
{
    use Auditable;

    public string $auditType = 'layout';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['status' => LayoutStatus::class, 'approved_at' => 'datetime'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LayoutItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
