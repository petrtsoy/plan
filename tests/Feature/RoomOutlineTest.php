<?php

namespace Tests\Feature;

use App\Enums\PlanSource;
use App\Enums\Role;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Подсказка контура помещения по стенам картинки: нужен python с PyMuPDF, иначе пропускается. */
class RoomOutlineTest extends TestCase
{
    use RefreshDatabase;

    /** Чертеж 1200 x 800: наружные стены и перегородка посередине, плюс серые размеры внутри. */
    private static function png(): string
    {
        $img = imagecreatetruecolor(1200, 800);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        $grey = imagecolorallocate($img, 205, 205, 205);
        imagefill($img, 0, 0, $white);
        imagesetthickness($img, 6);
        imagerectangle($img, 50, 50, 1150, 750, $black);
        imageline($img, 600, 50, 600, 750, $black);
        imagesetthickness($img, 2);
        imageline($img, 100, 120, 550, 120, $grey);

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return ob_get_clean();
    }

    private function plan(): Plan
    {
        $floor = $this->room()->floor;
        Storage::disk('local')->put('plans/этаж.png', self::png());

        // 1 px = 1 см: пиксель (300, 200) – это 3 м по x и 2 м по y.
        return Plan::create(['floor_id' => $floor->id, 'source' => PlanSource::Scan, 'source_path' => 'plans/этаж.png',
            'source_name' => 'этаж.png', 'image_path' => 'plans/этаж.png', 'width_px' => 1200, 'height_px' => 800,
            'meters_per_px' => 0.01]);
    }

    private function ready(): void
    {
        $py = new Process([config('services.python.bin'), '-c', 'import fitz']);
        $py->run();
        if (! $py->isSuccessful()) {
            $this->markTestSkipped('нет python с PyMuPDF');
        }
    }

    public function test_click_inside_room_gives_contour_by_walls(): void
    {
        $this->ready();
        Storage::fake('local');
        $plan = $this->plan();
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        // Щелчок в левой половине: контур по внутренней стороне стен, примерно 5,4 x 6,9 м.
        $res = $this->postJson("/plans/{$plan->id}/outline", ['x' => 3, 'y' => 4])->assertOk();
        $polygon = $res->json('polygon');
        $this->assertCount(4, $polygon, 'прямоугольная комната – четыре угла');
        $this->assertEqualsWithDelta(0.55, min(array_column($polygon, 0)), 0.1);
        $this->assertEqualsWithDelta(5.95, max(array_column($polygon, 0)), 0.1);
        $this->assertEqualsWithDelta(0.55, min(array_column($polygon, 1)), 0.1);
        $this->assertEqualsWithDelta(7.45, max(array_column($polygon, 1)), 0.1);
        $this->assertEqualsWithDelta(37, $res->json('area'), 1.5, 'серые размерные линии контур не рвут');

        // Контур сразу сохраняется помещению как обведенный руками.
        $room = $this->postJson("/plans/{$plan->id}/outline", ['x' => 9, 'y' => 4])->assertOk()->json('polygon');
        $this->putJson('/rooms/'.$plan->floor->rooms()->first()->id, ['polygon' => $room])->assertOk();

        // Щелчок за стенами (поле листа) и вне чертежа – понятная ошибка, а не пустой контур.
        $this->postJson("/plans/{$plan->id}/outline", ['x' => 0.1, 'y' => 0.1])->assertStatus(422)->assertJsonValidationErrors('x');
        $this->postJson("/plans/{$plan->id}/outline", ['x' => 50, 'y' => 50])->assertStatus(422)->assertJsonValidationErrors('x');
    }

    public function test_viewer_does_not_get_the_tool(): void
    {
        Storage::fake('local');
        $plan = $this->plan();
        $this->actingAs(User::factory()->role(Role::Viewer)->create());

        $this->postJson("/plans/{$plan->id}/outline", ['x' => 3, 'y' => 4])->assertForbidden();
        $this->get("/floors/{$plan->floor_id}")->assertOk()->assertDontSee('data-tool="wand"', false);
    }
}
