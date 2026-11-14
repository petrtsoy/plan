<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Category;
use App\Models\Layer;
use App\Models\Layout;
use App\Models\Office;
use App\Models\Project;
use App\Models\Room;
use App\Services\AssetMover;
use App\Services\Projects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    /** Проект "лаборатория в Астане": два анализатора с заземлением и сливом, один бокс. */
    private function project(): array
    {
        $category = Category::create(['layer_id' => Layer::create(['code' => 'lab', 'name' => 'Лаборатория'])->id, 'name' => 'Анализаторы']);
        $project = Project::create(['name' => 'Лаборатория в Астане']);
        $analyzers = $project->needs()->create(['category_id' => $category->id, 'label' => 'Гематологический анализатор', 'quantity' => 2,
            'width_mm' => 600, 'depth_mm' => 550, 'requirements' => ['grounding' => true, 'drain' => true]]);
        $box = $project->needs()->create(['category_id' => $category->id, 'label' => 'Ламинарный бокс']);

        return [$project, $analyzers, $box, $category];
    }

    /** Кандидат с этажом, одним помещением и вариантом проекта. */
    private function candidate(Project $project, string $name, array $specs): array
    {
        $building = app(Projects::class)->addCandidate($project, ['name' => $name, 'address' => 'Астана', 'price' => 1500000]);
        $floor = $building->floors()->create(['level' => 1, 'name' => '1 этаж']);
        $room = Room::create(['floor_id' => $floor->id, 'name' => 'Помещение', 'polygon' => [[0, 0], [8, 0], [8, 5], [0, 5]], 'specs' => $specs]);
        $layout = Layout::create(['floor_id' => $floor->id, 'project_id' => $project->id, 'name' => 'Вариант 1']);

        return [$building, $room, $layout];
    }

    public function test_candidates_are_compared_by_placed_needs_and_room_fit(): void
    {
        [$project, $analyzers, $box] = $this->project();
        [$a, $roomA, $layoutA] = $this->candidate($project, 'БЦ "Нурлы"', ['grounding' => true, 'drain' => true]);
        [$b, $roomB, $layoutB] = $this->candidate($project, 'Особняк на Кенесары', ['grounding' => true]);

        foreach ([[1, 1], [3, 1]] as [$x, $y]) {
            $layoutA->items()->create(['need_id' => $analyzers->id, 'room_id' => $roomA->id, 'x' => $x, 'y' => $y]);
        }
        $layoutA->items()->create(['need_id' => $box->id, 'room_id' => $roomA->id, 'x' => 6, 'y' => 3]);
        $layoutB->items()->create(['need_id' => $analyzers->id, 'room_id' => $roomB->id, 'x' => 1, 'y' => 1]);

        $rows = collect(app(Projects::class)->compare($project))->keyBy(fn ($r) => $r['building']->name);

        $this->assertSame([3, 3], [$rows['БЦ "Нурлы"']['placed'], $rows['БЦ "Нурлы"']['total']]);
        $this->assertSame([], $rows['БЦ "Нурлы"']['unfit']);
        $this->assertSame(1, $rows['Особняк на Кенесары']['placed']);
        $this->assertSame(['Гематологический анализатор – Помещение: Слив в канализацию'], $rows['Особняк на Кенесары']['unfit']);

        // Кандидаты не видны среди зданий филиалов.
        $this->assertSame(0, Building::real()->count());
    }

    public function test_real_assets_cannot_be_moved_into_candidate(): void
    {
        [$project, , , $category] = $this->project();
        [, $candidateRoom] = $this->candidate($project, 'Кандидат', []);
        $real = $this->room();
        $asset = Asset::create(['category_id' => $category->id, 'name' => 'Анализатор', 'office_id' => $real->floor->building->office_id, 'room_id' => $real->id]);

        $this->expectException(ValidationException::class);
        app(AssetMover::class)->move($asset, ['room_id' => $candidateRoom->id]);
    }

    public function test_choose_makes_building_real_and_closes_project(): void
    {
        [$project] = $this->project();
        [$a] = $this->candidate($project, 'А', []);
        [$b] = $this->candidate($project, 'Б', []);
        $svc = app(Projects::class);

        // Филиала у проекта нет – без него выбрать нельзя.
        try {
            $svc->choose($project, $a);
            $this->fail('Ожидали отказ без филиала');
        } catch (ValidationException) {
        }

        $office = Office::create(['hr_id' => 5, 'name' => 'Астанинский филиал']);
        $svc->choose($project, $a, $office->id, 'Дешевле и есть слив');

        $project->refresh();
        $this->assertSame(ProjectStatus::Chosen, $project->status);
        $this->assertTrue($project->chosenBuilding->is($a));
        $this->assertFalse($a->fresh()->is_candidate);
        $this->assertSame($office->id, $a->fresh()->office_id);
        $this->assertTrue($b->fresh()->is_candidate);
        $this->assertSame(['А'], $office->buildings()->pluck('name')->all());

        // Закрытый проект не меняется.
        $this->expectException(ValidationException::class);
        $svc->addCandidate($project, ['name' => 'В']);
    }

    public function test_reject_keeps_candidates_for_history(): void
    {
        [$project] = $this->project();
        $this->candidate($project, 'А', []);

        app(Projects::class)->reject($project, 'Ни одно не подошло по площади');

        $this->assertSame(ProjectStatus::Rejected, $project->fresh()->status);
        $this->assertSame(1, $project->buildings()->where('is_candidate', true)->count());
    }
}
