<?php

namespace App\Http\Controllers;

use App\Enums\Area;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Floor;
use App\Models\Layer;
use App\Models\Plan;
use App\Services\AssetMover;
use App\Services\FloorPlans;
use App\Support\AssetIcons;
use App\Services\ProjectImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Этаж на плане: подложка, помещения и расставленные единицы. Координаты в метрах этажа;
 * пока масштаб не откалиброван, метр плана – пиксель картинки (Plan::toMeters).
 */
class FloorController extends Controller
{
    public function show(Request $request, Floor $floor): View
    {
        $floor->load(['building.office', 'plan', 'rooms']);
        abort_if($floor->building->is_candidate, 404);

        $user = $request->user();
        $days = (int) config('services.alerts.maintenance_days');
        $assets = $floor->assets()->with(['category.layer', 'model', 'maintenancePlans' => fn ($q) => $q->where('is_active', true)])
            ->whereNotNull('x')->where('status', '!=', AssetStatus::WrittenOff)->get();

        return view('floors.show', [
            'floor' => $floor,
            'canPlans' => $user->edits(Area::Plans),
            'canAssets' => $user->edits(Area::Assets),
            'categories' => Category::with('layer')->orderBy('layer_id')->orderBy('sort')->orderBy('name')->get(),
            'map' => [
                'plan' => $floor->plan ? [
                    'id' => $floor->plan->id,
                    'url' => route('plans.image', $floor->plan),
                    'w' => $floor->plan->width_px,
                    'h' => $floor->plan->height_px,
                    'k' => $floor->plan->meters_per_px ?? 1,
                    'ox' => $floor->plan->offset_x,
                    'oy' => $floor->plan->offset_y,
                    'calibrated' => $floor->plan->isCalibrated(),
                ] : null,
                'layers' => Layer::orderBy('sort')->get(['id', 'name', 'color', 'visible']),
                'rooms' => $floor->rooms->filter(fn ($r) => $r->polygon)->map(fn ($r) => [
                    'id' => $r->id, 'number' => $r->number, 'name' => $r->name, 'title' => $r->title,
                    'purpose' => $r->purpose, 'department' => $r->department,
                    'area' => $r->area_m2 !== null ? (float) $r->area_m2 : null, 'polygon' => $r->polygon,
                ])->values(),
                // Помещения из экспликации проекта без контура: метка "обведите меня".
                'pending' => $floor->rooms->filter(fn ($r) => ! $r->polygon && $r->label_x !== null)->map(fn ($r) => [
                    'id' => $r->id, 'number' => $r->number, 'name' => $r->name, 'title' => $r->title,
                    'purpose' => $r->purpose, 'department' => $r->department,
                    'area' => $r->area_m2 !== null ? (float) $r->area_m2 : null, 'label' => [$r->label_x, $r->label_y],
                ])->values(),
                'assets' => $assets->map(fn (Asset $a) => $this->assetPoint($a, $days))->values(),
                'unplaced' => $floor->building->office_id
                    ? Asset::where('office_id', $floor->building->office_id)->whereNull('floor_id')
                        ->where('status', '!=', AssetStatus::WrittenOff)->with(['category', 'model'])->orderBy('name')->limit(500)->get()
                        ->map(fn (Asset $a) => ['id' => $a->id, 'title' => $a->title, 'inv' => $a->inventory_number])->values()
                    : [],
                'urls' => [
                    'calibrate' => $floor->plan ? route('plans.calibrate', $floor->plan) : null,
                    'crop' => $floor->plan ? route('plans.crop', $floor->plan) : null,
                    'rooms' => route('floors.rooms.store', $floor),
                    'room' => route('rooms.update', ['room' => '__ID__']),
                    'assets' => route('floors.assets.store', $floor),
                    'place' => route('assets.place', ['asset' => '__ID__']),
                    'import' => route('floors.import', $floor),
                ],
            ],
        ]);
    }

    public function update(Request $request, Floor $floor): RedirectResponse
    {
        $floor->update(BuildingController::validatedFloor($request, $floor->building, $floor->id));

        return back()->with('ok', 'Сохранено.');
    }

    public function uploadPlan(Request $request, Floor $floor, FloorPlans $plans): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:65536'],
            'page' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], ['file.max' => 'Файл больше 64 МБ.']);

        $plan = $plans->import($floor, $request->file('file'), $request->integer('page', 1));

        return back()->with('ok', $plan->isCalibrated()
            ? 'Подложка заменена. Проверьте масштаб: "Масштаб" – две точки и расстояние.'
            : 'Чертеж загружен. Задайте масштаб: "Масштаб" – две точки известного отрезка и его длина.');
    }

    public function planImage(Plan $plan): BinaryFileResponse
    {
        $path = Storage::disk('local')->path($plan->image_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=31536000, immutable']);
    }

    public function calibrate(Request $request, Plan $plan, FloorPlans $plans): JsonResponse
    {
        // Две точки – длина в метрах, контур – площадь в м².
        $data = $request->validate([
            'points' => ['required', 'array', 'min:2', 'max:200'],
            'points.*' => ['array', 'size:2'], 'points.*.*' => ['numeric'],
            'value' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ], ['value.required' => 'Укажите длину или площадь.']);

        $plans->calibrate($plan, array_map(fn ($p) => array_map('floatval', $p), $data['points']), (float) $data['value']);

        return response()->json(['ok' => true, 'message' => 'Масштаб задан: 1 м = '.round(1 / $plan->meters_per_px).' px.']);
    }

    /**
     * Импорт из рабочего проекта: помещения из экспликации, мебель и оборудование по меткам.
     * Без confirm – что получится (просмотр), с confirm – создать.
     */
    public function import(Request $request, Floor $floor, ProjectImport $import, AssetMover $mover): JsonResponse
    {
        $data = $request->validate([
            'marks_page' => ['nullable', 'integer', 'min:1', 'max:999'],
            'spec_page' => ['nullable', 'integer', 'min:1', 'max:999'],
            'categories' => ['array'], 'categories.*' => ['nullable', 'integer', 'exists:categories,id'],
            'confirm' => ['boolean'],
        ]);
        abort_unless($floor->plan, 422, 'Сначала загрузите подложку – лист проекта с планом этажа.');
        abort_if($floor->building->is_candidate, 422, 'Здание проекта: сюда – только варианты расстановки.');

        $marks = $data['marks_page'] ?? null;
        $spec = $data['spec_page'] ?? null;
        $result = $import->analyze($floor->plan, $marks, $spec);

        if (! $request->boolean('confirm')) {
            $cats = Category::with('layer')->get();

            return response()->json(['ok' => true] + $result + [
                'suggest' => collect($result['groups'])->map(fn ($g, $key) => self::suggestCategory($key, $cats)?->id)->all(),
            ]);
        }

        // Единицы ставит тот, кто правит расстановку; без этого права – только помещения.
        $categories = $request->user()->edits(Area::Assets) ? array_filter($data['categories'] ?? []) : [];
        $done = $import->apply($floor->plan, $result, $categories, $mover);
        $message = "Импорт: помещений новых {$done['rooms']}, единиц {$done['assets']}.";
        $request->session()->flash('ok', $message);

        return response()->json(['ok' => true, 'message' => $message]);
    }

    /**
     * Основная категория группы спецификации ("Прочее…" в слое): мебель – слой мебели,
     * оборудование и электрооборудование – медицинское. Точнее – по названию при импорте.
     */
    private static function suggestCategory(string $group, $categories): ?Category
    {
        $layer = match (true) {
            str_contains($group, 'мебел') => 'furniture',
            str_contains($group, 'лаборатор') => 'lab',
            str_contains($group, 'оборуд') => 'medical',
            default => null,
        };

        $inLayer = $layer ? $categories->filter(fn ($c) => $c->layer?->code === $layer) : collect();

        return $inLayer->first(fn ($c) => str_starts_with($c->name, 'Проч')) ?? $inLayer->first();
    }

    public function crop(Request $request, Plan $plan, FloorPlans $plans): JsonResponse
    {
        $data = $request->validate([
            'p1' => ['required', 'array', 'size:2'], 'p1.*' => ['numeric'],
            'p2' => ['required', 'array', 'size:2'], 'p2.*' => ['numeric'],
        ]);
        abort_unless($plan->is_current, 422, 'Обрезать можно только текущую подложку.');
        $plans->crop($plan, array_map('floatval', $data['p1']), array_map('floatval', $data['p2']));

        return response()->json(['ok' => true, 'message' => 'Чертеж обрезан. Прежний вариант сохранен в истории подложек.']);
    }

    /** Точка единицы для плана: цвет слоя и срок ТО (худший из действующих). */
    public static function assetPoint(Asset $a, int $days): array
    {
        $next = $a->maintenancePlans->where('is_active', true)->pluck('next_due_at')->filter()->min();
        $due = match (true) {
            $next === null => 'none',
            $next->lt(today()) => 'overdue',
            $next->lte(today()->addDays($days)) => 'soon',
            default => 'ok',
        };

        return [
            'id' => $a->id,
            'title' => $a->title,
            'inv' => $a->inventory_number,
            'x' => $a->x, 'y' => $a->y,
            'layer' => $a->category->layer_id,
            'color' => $a->category->display_color,
            'icon' => AssetIcons::for($a),
            'category' => $a->category->name,
            'status' => $a->status->value,
            'statusLabel' => $a->status->label(),
            'due' => $due,
            'next' => $next?->format('d.m.Y'),
            'url' => route('assets.show', $a),
        ];
    }
}
