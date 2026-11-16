<?php

namespace Tests\Feature;

use App\Enums\PlanSource;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Layer;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** История подложек: чистка и обрезка делают новую версию, вернуться можно к любой. */
class PlanVersionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_plan_returns_and_takes_placed_things_with_it(): void
    {
        $room = $this->room();
        $floor = $room->floor;
        // Масштаб не задан, поэтому координаты этажа – пиксели картинки: у версий они разные.
        $was = Plan::create(['floor_id' => $floor->id, 'source' => PlanSource::Pdf, 'source_path' => 'plans/x.pdf',
            'source_name' => 'x.pdf', 'image_path' => 'plans/x.png', 'width_px' => 1000, 'height_px' => 800, 'is_current' => false]);
        $now = Plan::create(['floor_id' => $floor->id, 'source' => PlanSource::Pdf, 'source_path' => 'plans/x.pdf',
            'source_name' => 'x.pdf', 'image_path' => 'plans/x-clean.png', 'width_px' => 500, 'height_px' => 400,
            'clean' => ['hide' => ['L:1.00:0,0,0'], 'pdf' => 'plans/x-clean.pdf']]);
        $layer = Layer::create(['code' => 'lab', 'name' => 'Слой']);
        $category = Category::create(['layer_id' => $layer->id, 'name' => 'Анализаторы']);
        $asset = Asset::create(['category_id' => $category->id, 'name' => 'Анализатор', 'inventory_number' => 'INV-1',
            'office_id' => $floor->building->office_id, 'room_id' => $room->id, 'floor_id' => $floor->id, 'x' => 100, 'y' => 50]);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->get(route('floors.show', $floor))->assertOk()->assertSee('История подложек');
        $this->assertSame('скрыто типов: 1', $now->changeLabel());

        $this->post(route('plans.restore', $was))->assertRedirect(route('floors.show', $floor));

        // Текущая подложка – всегда последняя версия, поэтому возврат это копия прежней.
        $back = $floor->fresh()->plan;
        $this->assertNotContains($back->id, [$was->id, $now->id]);
        $this->assertSame([$was->image_path, $was->width_px], [$back->image_path, $back->width_px]);
        $this->assertSame($was->id, $back->calibration['restored_from']);
        $this->assertSame('возврат прежней', $back->changeLabel());
        $this->assertFalse($now->fresh()->is_current);
        // Картинка вдвое крупнее прежней – расставленное переехало на те же места чертежа.
        $this->assertSame([[0, 0], [12, 0], [12, 8], [0, 8]], $room->fresh()->polygon);
        $this->assertSame([200.0, 100.0], [$asset->fresh()->x, $asset->fresh()->y]);

        // Текущую возвращать нечего.
        $this->post(route('plans.restore', $back))->assertSessionHasErrors('plan');
    }
}
