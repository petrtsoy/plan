<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Role: string
{
    use HasOptions;

    /** Все, включая пользователей системы и настройки. */
    case Admin = 'admin';
    /** Инженер по медтехнике и АХЧ: оборудование, ТО, планы и размещение. */
    case Engineer = 'engineer';
    /** Бухгалтерия и комиссия: инвентаризации. */
    case Accountant = 'accountant';
    /** Только смотрит: заведующие отделениями, руководство. */
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Engineer => 'Инженер',
            self::Accountant => 'Бухгалтер',
            self::Viewer => 'Просмотр',
        };
    }

    /** @return list<Area> что роль правит */
    public function areas(): array
    {
        return match ($this) {
            self::Admin => Area::cases(),
            self::Engineer => [Area::Plans, Area::Assets, Area::Inventory],
            self::Accountant => [Area::Inventory],
            self::Viewer => [],
        };
    }

    public function edits(Area $area): bool
    {
        return in_array($area, $this->areas(), true);
    }
}
