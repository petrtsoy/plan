<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Layer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Обрезка подложки по рамке: картинка – только кусок, расставленное остается на своих местах. */
class PlanCropTest extends TestCase
{
    use RefreshDatabase;

    private function setUpFloor(): array
    {
        Storage::fake('local');
        $room = $this->room();
        $floor = $room->floor;
        $this->actingAs(User::factory()->role(Role::Engineer)->create());
        $room->update(['polygon' => [[300, 200], [500, 200], [500, 400], [300, 400]]]);
        $cat = Category::create(['layer_id' => Layer::create(['code' => 'furniture', 'name' => 'Мебель'])->id, 'name' => 'Столы']);
        $asset = Asset::create(['category_id' => $cat->id, 'name' => 'Стол', 'office_id' => $floor->building->office_id,
            'floor_id' => $floor->id, 'room_id' => $room->id, 'x' => 400, 'y' => 300]);
        $this->post("/floors/{$floor->id}/plans", ['file' => UploadedFile::fake()->image('этаж.png', 1200, 800)])->assertRedirect();

        return [$floor, $room, $asset, $floor->fresh()->plan];
    }

    public function test_crop_uncalibrated_scan_remaps_pixels(): void
    {
        [$floor, $room, $asset, $plan] = $this->setUpFloor();

        $this->postJson("/plans/{$plan->id}/crop", ['p1' => [800, 600], 'p2' => [200, 100]])->assertOk();

        $new = $floor->fresh()->plan;
        $this->assertNotEquals($plan->id, $new->id);
        $this->assertSame([600, 500], [$new->width_px, $new->height_px]);
        // Рамка – в долях листа.
        $this->assertEqualsWithDelta([1 / 6, 0.125, 2 / 3, 0.75], [$new->crop['x0'], $new->crop['y0'], $new->crop['x1'], $new->crop['y1']], 1e-9);
        $this->assertEquals([200, 200], [$asset->fresh()->x, $asset->fresh()->y]);
        $this->assertEquals([[100, 100], [300, 100], [300, 300], [100, 300]], $room->fresh()->polygon);
    }

    public function test_crop_calibrated_keeps_meters(): void
    {
        [$floor, $room, $asset, $plan] = $this->setUpFloor();
        $this->postJson("/plans/{$plan->id}/calibrate", ['points' => [[0, 0], [100, 0]], 'value' => 1])->assertOk();
        $before = [$asset->fresh()->x, $asset->fresh()->y];

        $this->postJson("/plans/{$plan->id}/crop", ['p1' => [2, 1], 'p2' => [8, 6]])->assertOk();

        $new = $floor->fresh()->plan;
        $this->assertSame([600, 500], [$new->width_px, $new->height_px]);
        $this->assertEquals($before, [$asset->fresh()->x, $asset->fresh()->y]);
        $this->assertEqualsWithDelta(0.01, $new->meters_per_px, 1e-9);
        $this->assertEquals([2.0, 1.0], [$new->offset_x, $new->offset_y]);
        // Пиксель новой картинки (200, 200) – та же точка, где стоит стол.
        $this->assertEquals($before, $new->toMeters(200, 200));
    }

    public function test_too_small_frame_refused(): void
    {
        [, , , $plan] = $this->setUpFloor();
        $this->postJson("/plans/{$plan->id}/crop", ['p1' => [10, 10], 'p2' => [30, 30]])->assertUnprocessable();
    }
}
