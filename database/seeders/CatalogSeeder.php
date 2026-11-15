<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Layer;
use Illuminate\Database\Seeder;

/**
 * Начальные слои и категории. Можно и на production: повторный запуск только
 * добавит недостающее (по коду слоя и названию категории), правленное не трогает.
 */
class CatalogSeeder extends Seeder
{
    /** Слой => [название, цвет, категории: [название, медизделие, СИ, ИТ]]. */
    private const CATALOG = [
        'medical' => ['Медицинское оборудование', '#dc2626', [
            ['Ультразвуковая диагностика', true, false, false],
            ['Рентгенология и КТ', true, false, false],
            ['Функциональная диагностика', true, true, false],
            ['Мониторы и аппараты ИВЛ', true, true, false],
            ['Стерилизация и дезинфекция', true, false, false],
            ['Холодильное оборудование', true, true, false],
            ['Прочее медицинское оборудование', true, false, false],
        ]],
        'lab' => ['Лабораторное оборудование', '#7c3aed', [
            ['Анализаторы', true, true, false],
            ['Центрифуги', true, false, false],
            ['Микроскопы', true, false, false],
            ['ПЦР и амплификаторы', true, true, false],
            ['Дозаторы и весы', false, true, false],
            ['Боксы и шкафы безопасности', true, false, false],
            ['Прочее лабораторное оборудование', true, false, false],
        ]],
        'it' => ['ИТ-оборудование', '#2563eb', [
            ['Компьютеры и ноутбуки', false, false, true],
            ['Принтеры и МФУ', false, false, true],
            ['Сетевое оборудование', false, false, true],
            ['Камеры видеонаблюдения', false, false, true],
            ['Сетевые розетки', false, false, true],
        ]],
        'furniture' => ['Мебель', '#a16207', [
            ['Столы', false, false, false],
            ['Стулья и кресла', false, false, false],
            ['Шкафы и стеллажи', false, false, false],
            ['Кушетки и кровати', false, false, false],
            ['Прочая мебель', false, false, false],
        ]],
        'fire' => ['Пожарная безопасность', '#ea580c', [
            ['Огнетушители', false, false, false],
            ['Датчики пожарной сигнализации', false, false, false],
            ['Пожарные краны', false, false, false],
            ['Знаки и планы эвакуации', false, false, false],
        ]],
        'utilities' => ['Инженерные сети', '#0891b2', [
            ['Электрощиты и ИБП', false, false, false],
            ['Точки медицинских газов', false, false, false],
            ['Кондиционеры и вентиляция', false, false, false],
            ['Датчики температуры и влажности', false, true, false],
            ['Прочее инженерное оборудование', false, false, false],
        ]],
    ];

    public function run(): void
    {
        $sort = 10;
        foreach (self::CATALOG as $code => [$name, $color, $categories]) {
            $layer = Layer::firstOrCreate(['code' => $code], ['name' => $name, 'color' => $color, 'sort' => $sort]);
            $sort += 10;
            foreach ($categories as $i => [$cat, $medical, $measuring, $it]) {
                Category::firstOrCreate(['layer_id' => $layer->id, 'name' => $cat], [
                    'is_medical' => $medical,
                    'is_measuring' => $measuring,
                    'is_it' => $it,
                    'sort' => str_starts_with($cat, 'Проч') ? 900 : ($i + 1) * 10,
                ]);
            }
        }
    }
}
