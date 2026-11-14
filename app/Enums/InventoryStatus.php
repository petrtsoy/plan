<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InventoryStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Идет',
            self::Closed => 'Завершена',
        };
    }
}
