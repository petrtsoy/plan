<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ZoneKind;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Зоны этажа: обвести контур, тип, свой цвет – отдельным слоем поверх помещений. */
class ZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_zone_drawn_recolored_and_shown_as_layer(): void
    {
        $floor = $this->room()->floor;
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->postJson("/floors/{$floor->id}/zones", ['name' => 'Пожарный отсек 1', 'kind' => 'evacuation',
            'polygon' => [[0, 0], [10, 0], [10, 5], [0, 5]]])->assertOk()
            ->assertJsonPath('zone.color', '#16a34a')
            ->assertJsonPath('zone.area', 50)
            ->assertJsonPath('zone.kindLabel', 'Эвакуация');

        $zone = Zone::sole();
        $this->assertSame(ZoneKind::Evacuation, $zone->kind);

        // Свой цвет главнее цвета типа; новый контур – новая площадь.
        $this->putJson("/zones/{$zone->id}", ['color' => '#123456', 'polygon' => [[0, 0], [4, 0], [4, 4], [0, 4]]])
            ->assertOk()->assertJsonPath('zone.color', '#123456')->assertJsonPath('zone.area', 16);
        $this->putJson("/zones/{$zone->id}", ['color' => 'красный'])->assertJsonValidationErrors('color');
        $this->postJson("/floors/{$floor->id}/zones", ['name' => 'X', 'kind' => 'evacuation', 'polygon' => [[0, 0], [1, 1]]])
            ->assertJsonValidationErrors('polygon');

        $this->get("/floors/{$floor->id}")->assertOk()
            ->assertSee('data-zones', false)
            ->assertSee('Эвакуация')
            ->assertViewHas('map', fn ($map) => count($map['zones']) === 1
                && $map['zones']->first()['polygon'] === [[0, 0], [4, 0], [4, 4], [0, 4]]
                && collect($map['zoneKinds'])->firstWhere('value', 'radiation')['label'] === 'Радиационный контроль');
    }

    public function test_viewer_does_not_draw_zones(): void
    {
        $floor = $this->room()->floor;
        $this->actingAs(User::factory()->role(Role::Viewer)->create());

        $this->postJson("/floors/{$floor->id}/zones", ['name' => 'Отдел', 'kind' => 'department',
            'polygon' => [[0, 0], [1, 0], [1, 1]]])->assertForbidden();
        $this->get("/floors/{$floor->id}")->assertOk()->assertDontSee('data-tool="zone"', false);
    }
}
