<?php

namespace App\Services;

/**
 * Инженерные свойства помещения и требования оборудования – один каталог для обоих.
 * У помещения в rooms.specs лежит то, что есть ("розетка 380 В: да", "мощность: 15 кВт"),
 * у модели в asset_models.requirements – что нужно. check() сверяет одно с другим
 * при размещении и примерке. Новое свойство – строка здесь, без миграции.
 *
 * Типы: bool – есть или нет; number – помещение должно дать не меньше; select – совпадение.
 */
class Specs
{
    public const GROUPS = [
        'power' => 'Электропитание',
        'water' => 'Вода и вентиляция',
        'gas' => 'Медицинские газы',
        'building' => 'Строительная часть',
        'protection' => 'Защита и режим',
    ];

    /** Ключ => [раздел, подпись, тип, единица или варианты]. */
    public static function definitions(): array
    {
        return [
            'power_kw' => ['power', 'Мощность', 'number', 'кВт'],
            'power_380' => ['power', 'Сеть 380 В', 'bool', null],
            'dedicated_line' => ['power', 'Выделенная линия', 'bool', null],
            'grounding' => ['power', 'Медицинское заземление', 'bool', null],
            'ups' => ['power', 'Бесперебойное питание', 'bool', null],

            'water' => ['water', 'Холодная вода', 'bool', null],
            'water_hot' => ['water', 'Горячая вода', 'bool', null],
            'drain' => ['water', 'Слив в канализацию', 'bool', null],
            'demineralized' => ['water', 'Деминерализованная вода', 'bool', null],
            'ventilation' => ['water', 'Приточно-вытяжная вентиляция', 'bool', null],
            'fume_hood' => ['water', 'Вытяжной шкаф или местный отсос', 'bool', null],
            'air_conditioning' => ['water', 'Кондиционирование', 'bool', null],

            'gas_oxygen' => ['gas', 'Кислород', 'bool', null],
            'gas_vacuum' => ['gas', 'Вакуум', 'bool', null],
            'gas_air' => ['gas', 'Сжатый воздух', 'bool', null],
            'gas_n2o' => ['gas', 'Закись азота', 'bool', null],

            'floor_load' => ['building', 'Нагрузка на перекрытие', 'number', 'кг/м²'],
            'door_width' => ['building', 'Ширина двери', 'number', 'мм'],
            'ceiling_height' => ['building', 'Высота потолка', 'number', 'мм'],
            'network' => ['building', 'Сетевая розетка', 'bool', null],

            'radiation_protection' => ['protection', 'Радиационная защита', 'bool', null],
            'rf_shielding' => ['protection', 'Экранирование (клетка Фарадея)', 'bool', null],
            'clean_class' => ['protection', 'Класс чистоты', 'select', ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D']],
        ];
    }

    /**
     * Чего не хватает помещению для оборудования.
     *
     * @return list<string> пустой – подходит; неизвестное у помещения (нет значения) – тоже нехватка:
     *                      лучше перепроверить на месте, чем молча поставить
     */
    public static function check(array $requirements, array $room): array
    {
        $defs = self::definitions();
        $missing = [];
        foreach ($requirements as $key => $need) {
            if (! isset($defs[$key]) || $need === null || $need === false || $need === '') {
                continue;
            }
            [, $label, $type, $unit] = $defs[$key];
            $have = $room[$key] ?? null;
            $ok = match ($type) {
                'bool' => $have === true,
                'number' => $have !== null && (float) $have >= (float) $need,
                'select' => $have !== null && (string) $have === (string) $need,
            };
            if (! $ok) {
                $missing[] = match ($type) {
                    'bool' => $label,
                    'number' => sprintf('%s: нужно %s %s, есть %s', $label, $need, $unit, $have ?? 'не указано'),
                    'select' => sprintf('%s: нужен %s, есть %s', $label, $need, $have ?? 'не указано'),
                };
            }
        }

        return $missing;
    }
}
