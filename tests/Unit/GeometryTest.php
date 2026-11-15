<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Services\Geometry;
use App\Services\Specs;
use PHPUnit\Framework\TestCase;

class GeometryTest extends TestCase
{
    public function test_area_and_contains(): void
    {
        $l = [[0, 0], [4, 0], [4, 2], [2, 2], [2, 4], [0, 4]];
        $this->assertSame(12.0, Geometry::area($l));
        $this->assertTrue(Geometry::contains($l, 1, 3));
        $this->assertFalse(Geometry::contains($l, 3, 3));
    }

    public function test_footprint_with_clearance_and_rotation(): void
    {
        $box = Geometry::footprint(5, 5, 0, 1000, 600, ['front' => 400]);
        $this->assertSame([[4.5, 4.7], [5.5, 4.7], [5.5, 5.7], [4.5, 5.7]], $box);

        $turned = Geometry::footprint(0, 0, 90, 1000, 600);
        $this->assertEqualsWithDelta(1.0 * 0.6, Geometry::area($turned), 0.0001);
        $this->assertEqualsWithDelta(0.3, $turned[0][0], 0.0001);
    }

    public function test_plan_pixels_to_meters_and_back(): void
    {
        $plan = new Plan(['offset_x' => 10, 'offset_y' => 5, 'rotation' => 30]);
        $plan->calibrate([[100, 100], [400, 500]], 10.0);
        $this->assertEqualsWithDelta(0.02, $plan->meters_per_px, 1e-9);

        // По площади: прямоугольник 500 x 200 px – это 10 м²: 1 px = 1 см.
        $byArea = new Plan;
        $byArea->calibrate([[0, 0], [500, 0], [500, 200], [0, 200]], 10.0);
        $this->assertEqualsWithDelta(0.01, $byArea->meters_per_px, 1e-9);
        $this->assertEquals(10, $byArea->calibration['area']);

        [$x, $y] = $plan->toMeters(250, 120);
        [$px, $py] = $plan->toPixels($x, $y);
        $this->assertEqualsWithDelta(250, $px, 1e-6);
        $this->assertEqualsWithDelta(120, $py, 1e-6);
    }

    public function test_specs_check(): void
    {
        $need = ['power_kw' => 3, 'grounding' => true, 'gas_oxygen' => true, 'clean_class' => 'B', 'unknown' => true];
        $room = ['power_kw' => 5, 'grounding' => true, 'clean_class' => 'C'];

        $missing = Specs::check($need, $room);
        $this->assertSame(['Кислород', 'Класс чистоты: нужен B, есть C'], $missing);
        $this->assertSame(['Мощность: нужно 3 кВт, есть 2'], Specs::check(['power_kw' => 3], ['power_kw' => 2]));
    }
}
