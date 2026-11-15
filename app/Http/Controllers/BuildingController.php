<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Office;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Здания филиалов и их этажи. В списках филиала кандидатов проектов нет (Office::buildings
 * – через Building::real()); страница самого кандидата открывается из проекта: этажи,
 * подложки и помещения ему нужны так же, настоящих единиц там просто не бывает.
 */
class BuildingController extends Controller
{
    public function index(): View
    {
        $offices = Office::where('is_active', true)
            ->with(['buildings' => fn ($q) => $q->withCount('floors')])
            ->orderByDesc('is_branch')->orderBy('name')
            ->get();

        return view('buildings.index', ['offices' => $offices]);
    }

    public function store(Request $request): RedirectResponse
    {
        $building = Building::create($this->validated($request) + ['is_candidate' => false]);

        return redirect()->route('buildings.show', $building)->with('ok', 'Здание добавлено. Теперь – этажи.');
    }

    public function show(Building $building): View
    {
        $building->load(['office', 'floors' => fn ($q) => $q->with('plan')->withCount(['rooms', 'assets', 'layouts'])]);

        return view('buildings.show', ['building' => $building]);
    }

    public function update(Request $request, Building $building): RedirectResponse
    {
        // Кандидата (у него может не быть филиала) правят на экране проекта.
        abort_if($building->is_candidate, 404);
        $building->update($this->validated($request));

        return back()->with('ok', 'Сохранено.');
    }

    public function storeFloor(Request $request, Building $building): RedirectResponse
    {
        $floor = $building->floors()->create($this->validatedFloor($request, $building));

        return redirect()->route('floors.show', $floor)->with('ok', 'Этаж добавлен. Загрузите чертеж.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'office_id' => ['required', 'exists:offices,id'],
            'name' => ['required', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:191'],
            'total_area_m2' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    public static function validatedFloor(Request $request, Building $building, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'level' => ['required', 'integer', 'between:-5,60', Rule::unique('floors')->where('building_id', $building->id)->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:191'],
            'height_m' => ['nullable', 'numeric', 'between:1.5,20'],
        ], ['level.unique' => 'Такой этаж в здании уже есть.']);
        $data['height_m'] ??= 3;

        return $data;
    }
}
