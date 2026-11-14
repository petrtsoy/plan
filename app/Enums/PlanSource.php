<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Из чего сделана подложка этажа. Показывается всегда картинка (или SVG), исходник хранится рядом. */
enum PlanSource: string
{
    use HasOptions;

    case Pdf = 'pdf';
    case Scan = 'scan';
    case Dxf = 'dxf';
    case Dwg = 'dwg';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Scan => 'Скан или фото',
            self::Dxf => 'DXF',
            self::Dwg => 'DWG',
        };
    }
}
