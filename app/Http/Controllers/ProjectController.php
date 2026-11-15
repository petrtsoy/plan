<?php

namespace App\Http\Controllers;

use App\Enums\Area;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Category;
use App\Models\Office;
use App\Models\Project;
use App\Models\ProjectNeed;
use App\Services\FloorPlans;
use App\Services\Layouts;
use App\Services\Projects;
use App\Services\Specs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Проекты: рассматриваем здания-кандидаты, примеряем в них потребности вариантами
 * расстановки и выбираем одно здание (или ни одного) через App\Services\Projects.
 */
class ProjectController extends Controller
{
    public function __construct(private Projects $projects, private Layouts $layouts) {}

    public function index(Request $request): View
    {
        $projects = Project::with('office', 'chosenBuilding')
            ->withCount(['buildings', 'needs', 'layouts'])
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('id')->get();

        return view('projects.index', [
            'projects' => $projects,
            'offices' => Office::where('is_active', true)->orderBy('name')->get(),
            'canEdit' => $request->user()->edits(Area::Plans),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = Project::create($this->validated($request) + ['user_id' => $request->user()->id]);

        return redirect()->route('projects.show', $project)
            ->with('ok', 'Проект создан. Добавьте, что нужно разместить, и здания-кандидаты.');
    }

    public function show(Request $request, Project $project): View
    {
        $project->load([
            'office', 'chosenBuilding', 'decidedBy',
            'needs.category', 'needs.model', 'needs.asset',
            'buildings.floors.plan', 'buildings.floors.rooms',
        ]);

        // Сравнение само перезагружает потребности, поэтому счетчик позиций – после него.
        $rows = $this->projects->compare($project);
        $variants = $this->variants($project);
        $project->needs->loadCount('items');

        return view('projects.show', [
            'project' => $project,
            'rows' => $rows,
            'variants' => $variants,
            'offices' => Office::where('is_active', true)->orderBy('name')->get(),
            'categories' => Category::with('layer')->orderBy('layer_id')->orderBy('sort')->orderBy('name')->get(),
            'models' => Layouts::modelOptions(),
            'movable' => $project->office_id
                ? Asset::where('office_id', $project->office_id)->where('status', '!=', AssetStatus::WrittenOff)
                    ->with(['category', 'model'])->orderBy('name')->limit(500)->get()
                : collect(),
            'canEdit' => $request->user()->edits(Area::Plans),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $project->update($this->validated($request));

        return back()->with('ok', 'Сохранено.');
    }

    /**
     * Отчет для руководства: кандидаты, что поместилось и чего не хватает, цена.
     * Печатается из браузера – страница свернута под @media print.
     */
    public function report(Project $project): View
    {
        $project->load(['office', 'chosenBuilding', 'decidedBy', 'needs.category', 'buildings.floors']);

        return view('projects.report', [
            'project' => $project,
            'rows' => $this->projects->compare($project),
            'variants' => $this->variants($project),
        ]);
    }

    /** Новое здание-кандидат: сразу с первым этажом и, если дали, с подложкой. */
    public function storeCandidate(Request $request, Project $project, FloorPlans $plans): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:191'],
            'total_area_m2' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_note' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'floor_name' => ['nullable', 'string', 'max:191'],
            'floor_level' => ['nullable', 'integer', 'between:-5,60'],
            'file' => ['nullable', 'file', 'max:65536'],
        ], ['file.max' => 'Файл больше 64 МБ.']);

        $building = $this->projects->addCandidate($project, array_diff_key($data, array_flip(['floor_name', 'floor_level', 'file'])));
        $floor = $building->floors()->create([
            'level' => $data['floor_level'] ?? 1,
            'name' => $data['floor_name'] ?: ($data['floor_level'] ?? 1).' этаж',
        ]);
        if ($request->hasFile('file')) {
            $plans->import($floor, $request->file('file'));

            return redirect()->route('floors.show', $floor)
                ->with('ok', 'Кандидат добавлен. Задайте масштаб и обведите помещения – потом примеряйте оборудование.');
        }

        return redirect()->route('projects.show', $project)->with('ok', 'Кандидат добавлен. Загрузите чертеж этажа.');
    }

    /** Цена, площадь и адрес кандидата – то, по чему здания сравнивают. */
    public function updateCandidate(Request $request, Building $building): RedirectResponse
    {
        abort_unless($building->is_candidate && $building->project_id, 404);
        $building->update($request->validate([
            'name' => ['required', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:191'],
            'total_area_m2' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_note' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]));

        return back()->with('ok', 'Сохранено.');
    }

    public function storeFloor(Request $request, Building $building): RedirectResponse
    {
        abort_unless($building->is_candidate && $building->project_id, 404);
        $floor = $building->floors()->create(BuildingController::validatedFloor($request, $building));

        return redirect()->route('floors.show', $floor)->with('ok', 'Этаж добавлен. Загрузите чертеж.');
    }

    public function storeNeed(Request $request, Project $project): RedirectResponse
    {
        $project->needs()->create($this->validatedNeed($request, $project));

        return back()->with('ok', 'Потребность добавлена.');
    }

    public function updateNeed(Request $request, ProjectNeed $need): RedirectResponse
    {
        $need->update($this->validatedNeed($request, $need->project));

        return back()->with('ok', 'Сохранено.');
    }

    public function destroyNeed(ProjectNeed $need): RedirectResponse
    {
        $project = $need->project;
        abort_unless($project->isOpen(), 422, 'Проект уже закрыт.');
        $need->delete();

        return redirect()->route('projects.show', $project)->with('ok', 'Потребность убрана.');
    }

    /** Итог: выбранное здание становится настоящим зданием филиала. */
    public function choose(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'building_id' => ['required', 'exists:buildings,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'decision' => ['nullable', 'string', 'max:5000'],
        ]);
        $building = Building::findOrFail($data['building_id']);
        $this->projects->choose($project, $building, $data['office_id'] ?: null, $data['decision'] ?? null);

        return back()->with('ok', "Выбрано: {$building->name}. Здание стало настоящим – его видно в списке зданий филиала.");
    }

    public function reject(Request $request, Project $project): RedirectResponse
    {
        $this->projects->reject($project, $request->validate(['decision' => ['nullable', 'string', 'max:5000']])['decision'] ?? null);

        return back()->with('ok', 'Отмечено: ни одно здание не выбрано. Кандидаты остались в проекте.');
    }

    /**
     * Варианты проекта по зданиям: что расставлено и что не влезает – для экрана и отчета.
     *
     * @return array<int, list<array<string, mixed>>> здание => варианты
     */
    private function variants(Project $project): array
    {
        $out = [];
        foreach ($project->layouts()->with(['floor.building'])->orderBy('id')->get() as $layout) {
            $items = $this->layouts->items($layout);
            $out[$layout->floor->building_id][] = [
                'layout' => $layout,
                'items' => $items,
                'fits' => count(array_filter($items, fn ($i) => $i['fit']['ok'])),
                'problems' => array_values(array_unique(array_merge(...array_map(
                    fn ($i) => array_map(fn ($p) => $i['label'].': '.$p, $i['fit']['problems']),
                    $items
                ) ?: [[]]))),
            ];
        }

        return $out;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'goal' => ['nullable', 'string', 'max:5000'],
            'office_id' => ['nullable', 'exists:offices,id'],
        ]);
    }

    private function validatedNeed(Request $request, Project $project): array
    {
        abort_unless($project->isOpen(), 422, 'Проект уже закрыт.');

        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'label' => ['required', 'string', 'max:191'],
            'quantity' => ['required', 'integer', 'between:1,999'],
            'model_id' => ['nullable', 'exists:asset_models,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'width_mm' => ['nullable', 'integer', 'between:1,100000'],
            'depth_mm' => ['nullable', 'integer', 'between:1,100000'],
            'area_m2' => ['nullable', 'numeric', 'between:0,100000'],
            'requirements' => ['array'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['requirements'] = Specs::values($data['requirements'] ?? []) ?: null;

        return $data;
    }
}
