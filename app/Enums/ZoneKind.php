<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Область на плане этажа: отдел или зона с ограничениями. */
enum ZoneKind: string
{
    use HasOptions;

    case Department = 'department';
    case Clean = 'clean';
    case Dirty = 'dirty';
    /** Зона радиационного контроля (рентген, КТ). */
    case Radiation = 'radiation';
    /** Граница магнитного поля МРТ. */
    case Magnetic = 'magnetic';
    case Pcr = 'pcr';
    /** Контролируемая зона с ограниченным доступом. */
    case Restricted = 'restricted';
    case Evacuation = 'evacuation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'Отдел',
            self::Clean => 'Чистая зона',
            self::Dirty => 'Грязная зона',
            self::Radiation => 'Радиационный контроль',
            self::Magnetic => 'Магнитное поле МРТ',
            self::Pcr => 'ПЦР-зона',
            self::Restricted => 'Ограниченный доступ',
            self::Evacuation => 'Эвакуация',
            self::Other => 'Другое',
        };
    }
}
