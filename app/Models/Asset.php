<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Единица учета: оборудование, мебель, огнетушитель. Место и закрепление
 * (филиал, помещение, точка, МОЛ, пользователь) меняет только AssetMover.
 */
class Asset extends Model
{
    use Auditable, SoftDeletes;

    public string $auditType = 'asset';

    /** Поля, которые меняются только через AssetMover (история передач). */
    public const PLACEMENT = ['office_id', 'room_id', 'floor_id', 'x', 'y', 'rotation', 'mol_id', 'employee_id'];

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['status' => 'in_use', 'rotation' => 0];

    protected static function booted(): void
    {
        static::creating(function (Asset $asset) {
            $asset->qr_code ??= Str::lower(Str::random(12));
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'x' => 'float',
            'y' => 'float',
            'rotation' => 'float',
            'price' => 'decimal:2',
            'registration_until' => 'date',
            'commissioned_at' => 'date',
            'purchased_at' => 'date',
            'warranty_until' => 'date',
            'written_off_at' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'model_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function mol(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'mol_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function moves(): HasMany
    {
        return $this->hasMany(AssetMove::class)->latest('moved_at')->latest('id');
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class)->latest('performed_at')->latest('id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isPlaced(): bool
    {
        return $this->floor_id !== null && $this->x !== null && $this->y !== null;
    }

    /** Название для списков и плана: свое или модели. */
    public function getTitleAttribute(): string
    {
        return $this->name ?: ($this->model?->title ?? $this->category->name);
    }
}
