<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MaintenanceResult: string
{
    use HasOptions;

    case Ok = 'ok';
    /** Годно с ограничениями (например, поверка не по всем диапазонам). */
    case Limited = 'limited';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Годно',
            self::Limited => 'С ограничениями',
            self::Failed => 'Не годно',
        };
    }
}
