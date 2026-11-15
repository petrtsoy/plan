<?php

namespace App\Http\Controllers;

use App\Enums\Area;
use App\Enums\AssetStatus;
use App\Enums\LayoutStatus;
use App\Enums\ProjectStatus;
use App\Models\Asset;
use App\Models\Floor;
use App\Models\Layout;
use App\Models\LayoutItem;
use App\Models\Project;
use App\Services\AssetMover;
use App\Services\Layouts;
use App\Services\Specs;
use App\Support\AssetIcons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Примерка на этаже: варианты расстановки, проверка "влезает ли" (Layouts::fit) и
 * утверждение варианта – перенос в текущее размещение через AssetMover.
 */
class LayoutController extends Controller
{
    public function __construct(private Layouts $layouts) {}

    /** Варианты этажа рядом: сколько позиций, сколько не влезает, сколько места занято. */
    public function index(Request $request, Floor $floor): View
    {
        $floor->load(['building.office', 'building.project']);
        $layouts = $floor->layouts()->with(['project', 'user', 'approver'])->get();

        return view('layouts.index', [
            'floor' => $floor,
            'rows' => $layouts->map(fn (Layout $layout) => ['layout' => $layout] + $this->summary($layout)),
            'projects' => Project::where('status', ProjectStatus::Open)->orderBy('name')->get(),
            'canEdit' => $request->user()->edits(Area::Plans),
        ]);
    }

    public function store(Request $request, Floor $floor): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'copy_from' => ['nullable', Rule::exists('layouts', 'id')->where('floor_id', $floor->id)],
        ]);

        $layout = $floor->layouts()->create(array_diff_key($data, ['copy_from' => null]) + ['user_id' => $request->user()->id]);
        if (! empty($data['copy_from'])) {
            foreach (LayoutItem::where('layout_id', $data['copy_from'])->get() as $item) {
                $layout->items()->create($item->only(['asset_id', 'model_id', 'need_id', 'label', 'room_id', 'x', 'y', 'rotation']));
            }
        }

        return redirect()->route('layouts.show', $layout)->with('ok', 'Вариант создан. Ставьте оборудование на план.');
    }

    public function show(Request $request, Layout $layout): View
    {
        $layout->load(['floor.building.office', 'floor.building.project', 'floor.plan', 'floor.rooms', 'items', 'project.needs.category', 'project.needs.model']);
        $floor = $layout->floor;
        $user = $request->user();
        $canEdit = $user->edits(Area::Plans) && $layout->status === LayoutStatus::Draft;

        // На плане варианта видно и то, что уже стоит на этаже: что переставляем, что обойти.
        $standing = Asset::where('floor_id', $floor->id)->whereNotNull('x')
            ->where('status', '!=', AssetStatus::WrittenOff)
            ->with(['category.layer', 'model'])->get();

        return view('layouts.show', [
            'layout' => $layout,
            'floor' => $floor,
            'canEdit' => $canEdit,
            'canApprove' => $user->edits(Area::Assets) && $layout->status === LayoutStatus::Draft && ! $floor->building->is_candidate,
            'map' => [
                'layoutId' => $layout->id,
                'floorId' => $floor->id,
                'canEdit' => $canEdit,
                'plan' => $floor->plan ? [
                    'url' => route('plans.image', $floor->plan),
                    'w' => $floor->plan->width_px,
                    'h' => $floor->plan->height_px,
                    'k' => $floor->plan->meters_per_px ?? 1,
                    'ox' => $floor->plan->offset_x,
                    'oy' => $floor->plan->offset_y,
                    'calibrated' => $floor->plan->isCalibrated(),
                ] : null,
                'rooms' => $floor->rooms->filter(fn ($r) => $r->polygon)->map(fn ($r) => [
                    'id' => $r->id, 'title' => $r->title, 'polygon' => $r->polygon,
                    'area' => $r->area_m2 !== null ? (float) $r->area_m2 : null,
                    'specs' => Specs::summary($r->specs ?? []),
                ])->values(),
                'standing' => $standing->map(fn (Asset $a) => [
                    'id' => $a->id, 'title' => $a->title, 'x' => $a->x, 'y' => $a->y,
                    'color' => $a->category->display_color, 'icon' => AssetIcons::for($a),
                    'url' => route('assets.show', $a),
                ])->values(),
                'items' => $this->layouts->items($layout),
                'needs' => $layout->project?->needs->map(fn ($n) => [
                    'id' => $n->id,
                    'label' => $n->label,
                    'quantity' => (int) $n->quantity,
                    'placed' => $layout->items->where('need_id', $n->id)->count(),
                ])->values() ?? [],
                'urls' => [
                    'items' => route('layouts.items.store', $layout),
                    'item' => route('layout-items.update', ['item' => '__ID__']),
                    'check' => route('layouts.check', $layout),
                ],
            ],
            'models' => Layouts::modelOptions(),
            'unplaced' => $canEdit && $floor->building->office_id
                ? Asset::where('office_id', $floor->building->office_id)
                    ->where('status', '!=', AssetStatus::WrittenOff)
                    ->with(['category', 'model'])->orderBy('name')->limit(500)->get()
                : collect(),
        ]);
    }

    public function update(Request $request, Layout $layout): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in([LayoutStatus::Draft->value, LayoutStatus::Archived->value])],
        ]);
        // Утвержденный вариант – история: его не переписывают.
        abort_if($layout->status === LayoutStatus::Approved && ($data['status'] ?? null), 422, 'Утвержденный вариант не меняется.');
        $layout->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('ok', 'Сохранено.');
    }

    public function storeItem(Request $request, Layout $layout): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'exists:assets,id'],
            'model_id' => ['nullable', 'exists:asset_models,id'],
            'need_id' => ['nullable', Rule::exists('project_needs', 'id')->where('project_id', $layout->project_id)],
            'label' => ['nullable', 'string', 'max:191'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'rotation' => ['nullable', 'numeric', 'between:-360,360'],
        ]);
        if (! array_filter([$data['asset_id'] ?? null, $data['model_id'] ?? null, $data['need_id'] ?? null])) {
            abort(422, 'Выберите, что ставим: модель, единицу или потребность проекта.');
        }
        $data['rotation'] ??= 0;

        $item = $this->layouts->addItem($layout, $data);

        return $this->json($layout, $this->layouts->label($item).' поставлено.');
    }

    public function updateItem(Request $request, LayoutItem $item): JsonResponse
    {
        $data = $request->validate([
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'rotation' => ['nullable', 'numeric', 'between:-360,360'],
            'label' => ['nullable', 'string', 'max:191'],
        ]);

        $this->layouts->moveItem($item, array_filter($data, fn ($v) => $v !== null));

        return $this->json($item->layout);
    }

    public function destroyItem(LayoutItem $item): JsonResponse
    {
        $layout = $item->layout;
        abort_if($layout->status !== LayoutStatus::Draft, 422, 'Вариант уже не черновик.');
        $label = $this->layouts->label($item);
        $item->delete();

        return $this->json($layout, "{$label} убрано из варианта.");
    }

    /** Проверка влезания по всем позициям: Geometry (след с зонами) и Specs::check(). */
    public function check(Layout $layout): JsonResponse
    {
        return $this->json($layout);
    }

    public function approve(Request $request, Layout $layout, AssetMover $mover): RedirectResponse
    {
        $done = $this->layouts->approve($layout, $mover);
        $message = "Вариант утвержден: переставлено {$done['moved']}, заведено новых {$done['created']}";

        return redirect()->route('layouts.show', $layout)
            ->with('ok', $message.($done['skipped'] ? ", пропущено {$done['skipped']} (нет категории)" : '').'.');
    }

    private function json(Layout $layout, ?string $message = null): JsonResponse
    {
        $items = $this->layouts->items($layout);

        return response()->json([
            'ok' => true,
            'items' => $items,
            'summary' => $this->summaryOf($items),
            'message' => $message,
        ]);
    }

    /** Итог варианта для списка и сравнения. */
    private function summary(Layout $layout): array
    {
        return $this->summaryOf($this->layouts->items($layout));
    }

    private function summaryOf(array $items): array
    {
        $area = 0.0;
        $problems = [];
        foreach ($items as $item) {
            if ($item['width'] && $item['depth']) {
                $area += $item['width'] * $item['depth'] / 1e6;
            }
            foreach ($item['fit']['problems'] as $problem) {
                $problems[] = $item['label'].': '.$problem;
            }
        }

        return [
            'items' => count($items),
            'fits' => count(array_filter($items, fn ($i) => $i['fit']['ok'])),
            'area' => round($area, 2),
            'problems' => $problems,
        ];
    }
}
