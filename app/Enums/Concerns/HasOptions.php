<?php

namespace App\Enums\Concerns;

/** Значение => подпись для выпадающих списков. Енам задает label(). */
trait HasOptions
{
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $c) => $c->value, self::cases()),
            array_map(fn (self $c) => $c->label(), self::cases()),
        );
    }
}
