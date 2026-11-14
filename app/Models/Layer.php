<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Слой плана: включается галочкой, объединяет категории. */
class Layer extends Model
{
    use Auditable;

    public string $auditType = 'layer';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['visible' => true, 'color' => '#64748b', 'sort' => 100];

    protected function casts(): array
    {
        return ['visible' => 'boolean'];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('sort')->orderBy('name');
    }
}
