<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Класс потенциального риска медицинского изделия. */
enum RiskClass: string
{
    use HasOptions;

    case C1 = '1';
    case C2a = '2a';
    case C2b = '2b';
    case C3 = '3';

    public function label(): string
    {
        return match ($this) {
            self::C1 => '1 – низкая степень риска',
            self::C2a => '2а – средняя степень риска',
            self::C2b => '2б – повышенная степень риска',
            self::C3 => '3 – высокая степень риска',
        };
    }
}
