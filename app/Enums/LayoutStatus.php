<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Вариант расстановки: черновик для примерки, утвержденный переносится в текущее размещение. */
enum LayoutStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Approved = 'approved';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Approved => 'Утвержден',
            self::Archived => 'В архиве',
        };
    }
}
