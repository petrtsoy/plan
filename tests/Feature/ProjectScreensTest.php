<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\AssetModel;
use App\Models\Building;
use App\Models\Category;
use App\Models\Layer;
use App\Models\Layout;
use App\Models\Office;
use App\Models\Project;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Экраны проектов: кандидаты, потребности, сравнение, итог и отчет для руководства. */
class ProjectScreensTest extends TestCase
{
    use RefreshDatabase;

    private function engineer(): User
    {
        return User::factory()->role(Role::Engineer)->create();
    }

    /** Проект с потребностью на два анализатора и кандидатом с одним помещением. */
    private function project(array $specs = ['grounding' => true, 'drain' => true]): array
    {
        $layer = Layer::create(['code' => 'lab', 'name' => 'Лаборатория']);
        $category = Category::create(['layer_id' => $layer->id, 'name' => 'Анализаторы']);
        $model = AssetModel::create([
            'category_id' => $category->id, 'name' => 'Анализатор',
            'width_mm' => 1000, 'depth_mm' => 600,
            'requirements' => ['grounding' => true, 'drain' => true],
        ]);

        $project = Project::create(['name' => 'Лаборатория в Астане', 'goal' => 'Открыть лабораторию']);
        $need = $project->needs()->create([
            'category_id' => $category->id, 'model_id' => $model->id,
            'label' => 'Гематологический анализатор', 'quantity' => 2,
        ]);

        $building = Building::create(['project_id' => $project->id, 'is_candidate' => true,
            'name' => 'БЦ "Нурлы"', 'address' => 'Астана, Кенесары 1', 'price' => 1500000, 'price_note' => 'аренда в месяц']);
        $floor = $building->floors()->create(['level' => 1, 'name' => '1 этаж']);
        $room = Room::create(['floor_id' => $floor->id, 'number' => '1', 'name' => 'Лаборатория',
            'polygon' => [[0, 0], [8, 0], [8, 5], [0, 5]], 'specs' => $specs]);

        return [$project, $need, $building, $floor, $room, $category];
    }

    public function test_project_list_and_creation(): void
    {
        $engineer = $this->engineer();
        $office = Office::create(['hr_id' => 7, 'name' => 'Астанинский филиал']);

        $this->actingAs($engineer)->post(route('projects.store'), [
            'name' => 'Переезд поликлиники', 'goal' => 'Старое здание сносят', 'office_id' => $office->id,
        ])->assertRedirect();

        $project = Project::where('name', 'Переезд поликлиники')->sole();
        $this->assertSame(ProjectStatus::Open, $project->status);
        $this->assertSame($engineer->id, $project->user_id);

        $this->actingAs($engineer)->get(route('projects.index'))
            ->assertOk()->assertSee('Переезд поликлиники')->assertSee('Астанинский филиал');
    }

    public function test_candidate_with_a_floor_is_added_and_stays_out_of_office_building_lists(): void
    {
        $office = Office::create(['hr_id' => 8, 'name' => 'Астанинский филиал']);
        $project = Project::create(['name' => 'Лаборатория', 'office_id' => $office->id]);

        $this->actingAs($this->engineer())->post(route('projects.candidates.store', $project), [
            'name' => 'Особняк на Кенесары', 'address' => 'Кенесары 10',
            'total_area_m2' => 420.5, 'price' => 90000000, 'price_note' => 'покупка',
            'floor_level' => 1, 'floor_name' => '1 этаж',
        ])->assertRedirect(route('projects.show', $project));

        $candidate = Building::where('name', 'Особняк на Кенесары')->sole();
        $this->assertTrue($candidate->is_candidate);
        $this->assertSame($project->id, $candidate->project_id);
        $this->assertSame(1, $candidate->floors()->count());

        // Кандидат не попадает ни в список зданий филиала, ни в Building::real().
        $this->assertSame(0, Building::real()->count());
        $this->assertSame(0, $office->buildings()->count());
        $this->actingAs($this->engineer())->get(route('buildings.index'))
            ->assertOk()->assertDontSee('Особняк на Кенесары');

        // А этаж кандидата открывается: на нем обводят помещения и примеряют оборудование.
        $this->actingAs($this->engineer())->get(route('floors.show', $candidate->floors()->sole()))
            ->assertOk()->assertSee('здание проекта')->assertSee('вариантами расстановки');
    }

    public function test_needs_are_added_with_requirements_from_the_specs_catalog(): void
    {
        [$project, , , , , $category] = $this->project();

        $this->actingAs($this->engineer())->post(route('projects.needs.store', $project), [
            'category_id' => $category->id, 'label' => 'Ламинарный бокс', 'quantity' => 1,
            'width_mm' => 1200, 'depth_mm' => 700,
            'requirements' => ['ventilation' => '1', 'power_kw' => '1,5', 'clean_class' => 'B', 'ups' => '0'],
        ])->assertRedirect();

        $need = $project->needs()->where('label', 'Ламинарный бокс')->sole();
        $this->assertSame(['power_kw' => 1.5, 'ventilation' => true, 'clean_class' => 'B'], $need->requirements);
        $this->assertSame($need->requirements, $need->effectiveRequirements());
    }

    public function test_project_screen_compares_candidates_by_placed_needs(): void
    {
        [$project, $need, $building, $floor, $room] = $this->project();

        // В варианте проекта на кандидате расставлен один анализатор из двух.
        $layout = Layout::create(['floor_id' => $floor->id, 'project_id' => $project->id, 'name' => 'Вариант 1']);
        $layout->items()->create(['need_id' => $need->id, 'room_id' => $room->id, 'x' => 2, 'y' => 2, 'rotation' => 0]);

        $this->actingAs($this->engineer())->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('БЦ &quot;Нурлы&quot;', false)
            ->assertSee('Кенесары 1')
            ->assertSee('1 из 2')
            ->assertSee('Вариант 1')
            ->assertSee('Гематологический анализатор');
    }

    public function test_project_screen_shows_what_the_room_lacks(): void
    {
        [$project, $need, , $floor, $room] = $this->project(['grounding' => true]);
        $layout = Layout::create(['floor_id' => $floor->id, 'project_id' => $project->id, 'name' => 'Вариант 1']);
        $layout->items()->create(['need_id' => $need->id, 'room_id' => $room->id, 'x' => 2, 'y' => 2, 'rotation' => 0]);

        $this->actingAs($this->engineer())->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Слив в канализацию');
    }

    public function test_report_for_management_prints_candidates_needs_and_price(): void
    {
        [$project, $need, , $floor, $room] = $this->project(['grounding' => true]);
        $layout = Layout::create(['floor_id' => $floor->id, 'project_id' => $project->id, 'name' => 'Вариант 1']);
        $layout->items()->create(['need_id' => $need->id, 'room_id' => $room->id, 'x' => 2, 'y' => 2, 'rotation' => 0]);

        $this->actingAs(User::factory()->role(Role::Viewer)->create())->get(route('projects.report', $project))
            ->assertOk()
            ->assertSee('Отчет по проекту')
            ->assertSee('Гематологический анализатор')
            ->assertSee('1 500 000')
            ->assertSee('Слив в канализацию')
            ->assertSee('Проект рассматривается');
    }

    public function test_choosing_a_building_makes_it_real_and_rejecting_closes_the_project(): void
    {
        [$project, , $building] = $this->project();
        $office = Office::create(['hr_id' => 9, 'name' => 'Астанинский филиал']);

        $this->actingAs($this->engineer())->post(route('projects.choose', $project), [
            'building_id' => $building->id, 'office_id' => $office->id, 'decision' => 'Дешевле и есть слив',
        ])->assertRedirect();

        $project->refresh();
        $this->assertSame(ProjectStatus::Chosen, $project->status);
        $this->assertFalse($building->fresh()->is_candidate);
        $this->assertSame(['БЦ "Нурлы"'], $office->buildings()->pluck('name')->all());
        $this->actingAs($this->engineer())->get(route('projects.show', $project))
            ->assertOk()->assertSee('Дешевле и есть слив');

        // Закрытый проект больше не принимает кандидатов.
        $this->actingAs($this->engineer())
            ->post(route('projects.candidates.store', $project), ['name' => 'Еще один'])
            ->assertRedirect()->assertSessionHasErrors('project');

        [$other] = $this->project2();
        $this->actingAs($this->engineer())->post(route('projects.reject', $other), ['decision' => 'Ни одно не подошло'])
            ->assertRedirect();
        $this->assertSame(ProjectStatus::Rejected, $other->fresh()->status);
    }

    public function test_viewer_sees_projects_but_does_not_change_them(): void
    {
        [$project] = $this->project();
        $viewer = User::factory()->role(Role::Viewer)->create();

        $this->actingAs($viewer)->get(route('projects.index'))->assertOk();
        $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($viewer)->post(route('projects.needs.store', $project), [
            'category_id' => $project->needs->first()->category_id, 'label' => 'Нельзя', 'quantity' => 1,
        ])->assertForbidden();
        $this->actingAs($viewer)->post(route('projects.choose', $project), ['building_id' => 1])->assertForbidden();
    }

    private function project2(): array
    {
        $project = Project::create(['name' => 'Второй проект']);

        return [$project];
    }
}
