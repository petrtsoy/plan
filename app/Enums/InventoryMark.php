<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Отметка по позиции ведомости инвентаризации. */
enum InventoryMark: string
{
    use HasOptions;

    /** Еще не проверяли. */
    case Pending = 'pending';
    case Found = 'found';
    /** Найдено, но не в том помещении. */
    case Misplaced = 'misplaced';
    case Missing = 'missing';
    /** Найдено то, чего нет в ведомости (нет в учете или числится в другом месте). */
    case Surplus = 'surplus';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Не проверено',
            self::Found => 'На месте',
            self::Misplaced => 'Не на своем месте',
            self::Missing => 'Не найдено',
            self::Surplus => 'Излишек',
        };
    }
}
