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
     * Контур целиком внутри другого: все углы внутри и ни одна сторона не пересекает границу
     * (помещение бывает невыпуклым – одних углов мало: аппарат может "перешагнуть" выступ).
     */
    public static function within(array $inner, array $outer): bool
    {
        foreach ($inner as $p) {
            if (! self::contains($outer, $p[0], $p[1])) {
                return false;
            }
        }

        return ! self::edgesCross($inner, $outer);
    }

    /** Контуры налезают друг на друга: стороны пересекаются или один внутри другого. */
    public static function overlaps(array $a, array $b): bool
    {
        if (self::edgesCross($a, $b)) {
            return true;
        }

        return self::contains($b, $a[0][0], $a[0][1]) || self::contains($a, $b[0][0], $b[0][1]);
    }

    /** Есть ли пересечение сторон двух контуров. */
    private static function edgesCross(array $a, array $b): bool
    {
        $n = count($a);
        $m = count($b);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $m; $j++) {
                if (self::segmentsCross($a[$i], $a[($i + 1) % $n], $b[$j], $b[($j + 1) % $m])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Пересекаются ли отрезки p1p2 и p3p4 (касание в точке – тоже пересечение). */
    private static function segmentsCross(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d1 = self::cross($p3, $p4, $p1);
        $d2 = self::cross($p3, $p4, $p2);
        $d3 = self::cross($p1, $p2, $p3);
        $d4 = self::cross($p1, $p2, $p4);

        if ((($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0))) {
            return true;
        }

        // Отрезки на одной прямой: пересекаются, если точка лежит внутри другого отрезка.
        return (abs($d1) < 1e-9 && self::onSegment($p3, $p4, $p1))
            || (abs($d2) < 1e-9 && self::onSegment($p3, $p4, $p2))
            || (abs($d3) < 1e-9 && self::onSegment($p1, $p2, $p3))
            || (abs($d4) < 1e-9 && self::onSegment($p1, $p2, $p4));
    }

    /** С какой стороны от ab лежит точка c: больше нуля – слева, меньше – справа, ноль – на прямой. */
    private static function cross(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    private static function onSegment(array $a, array $b, array $p): bool
    {
        return $p[0] >= min($a[0], $b[0]) - 1e-9 && $p[0] <= max($a[0], $b[0]) + 1e-9
            && $p[1] >= min($a[1], $b[1]) - 1e-9 && $p[1] <= max($a[1], $b[1]) + 1e-9;
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
