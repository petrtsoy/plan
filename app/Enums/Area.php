<?php

namespace App\Enums;

/** Что роль может править. Смотреть могут все вошедшие. */
enum Area: string
{
    /** Здания, этажи, помещения, планы, зоны, варианты расстановки. */
    case Plans = 'plans';
    /** Оборудование и имущество, справочник моделей, ТО и поверка. */
    case Assets = 'assets';
    /** Инвентаризации: ведомости, отметки "на месте". */
    case Inventory = 'inventory';

    public function label(): string
    {
        return match ($this) {
            self::Plans => 'Здания и планы',
            self::Assets => 'Оборудование и ТО',
            self::Inventory => 'Инвентаризация',
        };
    }
}
