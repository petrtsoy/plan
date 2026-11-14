<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Итог проекта: выбрали одно здание или ни одного. */
enum ProjectStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Chosen = 'chosen';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Рассматривается',
            self::Chosen => 'Здание выбрано',
            self::Rejected => 'Ничего не выбрано',
        };
    }
}
