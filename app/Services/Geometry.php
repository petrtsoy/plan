<?php

namespace App\Services;

/** Геометрия на плане этажа: точки [x, y] в метрах. */
class Geometry
{
    /** Площадь многоугольника (формула шнурков), м². */
    public static function area(array $polygon): float
    {
        $n = count($polygon);
        if ($n < 3) {
            return 0.0;
        }
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $polygon[$i];
            [$x2, $y2] = $polygon[($i + 1) % $n];
            $sum += $x1 * $y2 - $x2 * $y1;
        }

        return abs($sum) / 2;
    }

    /** Точка внутри многоугольника (луч вправо). */
    public static function contains(array $polygon, float $x, float $y): bool
    {
        $inside = false;
        $n = count($polygon);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $polygon[$i];
            [$xj, $yj] = $polygon[$j];
            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Прямоугольник, который занимает оборудование вместе с сервисными зонами:
     * углы в метрах этажа. Центр – точка установки, поворот – по часовой, перед смотрит вниз (+y).
     *
     * @param  array{front?: int, back?: int, left?: int, right?: int}  $clearance  мм
     * @return list<array{0: float, 1: float}>
     */
    public static function footprint(float $x, float $y, float $rotation, int $widthMm, int $depthMm, array $clearance = []): array
    {
        $w = $widthMm / 1000;
        $d = $depthMm / 1000;
        $left = -$w / 2 - ($clearance['left'] ?? 0) / 1000;
        $right = $w / 2 + ($clearance['right'] ?? 0) / 1000;
        $back = -$d / 2 - ($clearance['back'] ?? 0) / 1000;
        $front = $d / 2 + ($clearance['front'] ?? 0) / 1000;
        $a = deg2rad($rotation);

        return array_map(fn ($p) => [
            round($x + $p[0] * cos($a) - $p[1] * sin($a), 3),
            round($y + $p[0] * sin($a) + $p[1] * cos($a), 3),
        ], [[$left, $back], [$right, $back], [$right, $front], [$left, $front]]);
    }
}
