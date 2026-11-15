<?php

namespace App\Http\Controllers;

use App\Enums\Area;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Floor;
use App\Models\Layer;
use App\Models\Plan;
use App\Services\FloorPlans;
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
                'assets' => $assets->map(fn (Asset $a) => $this->assetPoint($a, $days))->values(),
                'unplaced' => $floor->building->office_id
                    ? Asset::where('office_id', $floor->building->office_id)->whereNull('floor_id')
                        ->where('status', '!=', AssetStatus::WrittenOff)->with(['category', 'model'])->orderBy('name')->limit(500)->get()
                        ->map(fn (Asset $a) => ['id' => $a->id, 'title' => $a->title, 'inv' => $a->inventory_number])->values()
                    : [],
                'urls' => [
                    'calibrate' => $floor->plan ? route('plans.calibrate', $floor->plan) : null,
                    'rooms' => route('floors.rooms.store', $floor),
                    'room' => route('rooms.update', ['room' => '__ID__']),
                    'assets' => route('floors.assets.store', $floor),
                    'place' => route('assets.place', ['asset' => '__ID__']),
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
            'category' => $a->category->name,
            'status' => $a->status->value,
            'statusLabel' => $a->status->label(),
            'due' => $due,
            'next' => $next?->format('d.m.Y'),
            'url' => route('assets.show', $a),
        ];
    }
}
