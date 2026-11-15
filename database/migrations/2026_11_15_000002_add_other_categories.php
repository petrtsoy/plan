<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Категории "Прочее" в слоях: в них импорт из проекта кладет то, что не подошло по названию
 * к другим категориям. Только там, где слой уже есть (на production – из CatalogSeeder).
 */
return new class extends Migration
{
    public const OTHER = [
        'medical' => ['Прочее медицинское оборудование', true],
        'lab' => ['Прочее лабораторное оборудование', true],
        'furniture' => ['Прочая мебель', false],
        'utilities' => ['Прочее инженерное оборудование', false],
    ];

    public function up(): void
    {
        foreach (self::OTHER as $code => [$name, $medical]) {
            $layerId = DB::table('layers')->where('code', $code)->value('id');
            if ($layerId && ! DB::table('categories')->where('layer_id', $layerId)->where('name', $name)->exists()) {
                DB::table('categories')->insert(['layer_id' => $layerId, 'name' => $name, 'is_medical' => $medical,
                    'sort' => 900, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Категории могли успеть получить единицы – не удаляем.
    }
};
