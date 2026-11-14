<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Сервисная организация: ТО медтехники, поверка, перезарядка огнетушителей. */
class Contractor extends Model
{
    use Auditable;

    public string $auditType = 'contractor';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['contract_until' => 'date'];
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class);
    }
}
