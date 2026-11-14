<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Вид периодической работы с оборудованием: у каждого свой срок и свой сигнал. */
enum MaintenanceKind: string
{
    use HasOptions;

    /** Техническое обслуживание по договору или регламенту производителя. */
    case Service = 'service';
    /** Поверка средства измерений. */
    case Verification = 'verification';
    case Calibration = 'calibration';
    /** Квалификация лабораторного оборудования (IQ/OQ/PQ). */
    case Qualification = 'qualification';
    /** Проверка электробезопасности. */
    case Electrical = 'electrical';
    /** Перезарядка или освидетельствование огнетушителя. */
    case Recharge = 'recharge';
    case Inspection = 'inspection';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Техобслуживание',
            self::Verification => 'Поверка',
            self::Calibration => 'Калибровка',
            self::Qualification => 'Квалификация',
            self::Electrical => 'Электробезопасность',
            self::Recharge => 'Перезарядка',
            self::Inspection => 'Осмотр',
        };
    }
}
