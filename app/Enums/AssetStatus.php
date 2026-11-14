<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AssetStatus: string
{
    use HasOptions;

    case InUse = 'in_use';
    /** Исправно, но не используется: простой дорогого оборудования виден на плане. */
    case Idle = 'idle';
    case Repair = 'repair';
    case Storage = 'storage';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::InUse => 'В работе',
            self::Idle => 'Простой',
            self::Repair => 'В ремонте',
            self::Storage => 'На складе',
            self::WrittenOff => 'Списано',
        };
    }
}
