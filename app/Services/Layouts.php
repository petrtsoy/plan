<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\LayoutStatus;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Layout;
use App\Models\LayoutItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Примерка: позиции варианта расстановки и проверка, встанет ли оборудование.
 *
 * Проверяются две вещи: место (Geometry – след с сервисными зонами внутри контура помещения,
 * без наложений на другие позиции и на то, что уже стоит на этаже) и само помещение
 * (Specs::check – чего ему не хватает по инженерным требованиям модели). Утверждение
 * варианта переносит позиции в текущее размещение через AssetMover, в перемещении
 * остается ссылка на вариант (asset_moves.layout_id) – откуда взялось место.
 */
class Layouts
{
    public function __construct(private FloorPlans $plans) {}

    /** Позиция варианта: модель из справочника, существующая единица или потребность проекта. */
    public function addItem(Layout $layout, array $data): LayoutItem
    {
        $this->ensureDraft($layout);
        $item = $layout->items()->create($data + ['room_id' => $this->roomAt($layout, $data)]);

        return $item->fresh(['asset.model', 'model', 'need.model', 'room']);
    }

    /** Переставить или повернуть позицию: помещение определяется по контуру заново. */
    public function moveItem(LayoutItem $item, array $data): LayoutItem
    {
        $this->ensureDraft($item->layout);
        $item->update($data + ['room_id' => $this->roomAt($item->layout, $data + $item->only(['x', 'y']))]);

        return $item->fresh(['asset.model', 'model', 'need.model', 'room']);
    }

    /**
     * Позиции варианта для экрана и отчета: след, контур самого аппарата и что не так.
     *
     * @return list<array<string, mixed>>
     */
    public function items(Layout $layout): array
    {
        $items = $layout->items()->with(['asset.model', 'asset.category', 'model.category', 'need.model', 'room'])->orderBy('id')->get();
        $obstacles = $this->obstacles($layout, $items);

        return $items->map(fn (LayoutItem $item) => $this->describe($item, $items, $obstacles))->all();
    }

    /**
     * Встанет ли позиция: контур аппарата и его след с сервисными зонами, помещение,
     * наложения и чего не хватает помещению.
     *
     * @param  Collection<int, LayoutItem>  $siblings  другие позиции того же варианта
     * @param  list<array{label: string, polygon: list<array{0: float, 1: float}>}>  $obstacles  что уже стоит на этаже
     * @return array{ok: bool, problems: list<string>, missing: list<string>, overlaps: list<string>, notes: list<string>}
     */
    public function fit(LayoutItem $item, Collection $siblings, array $obstacles = []): array
    {
        $problems = $overlaps = $notes = [];
        // Без помещения требования не проверяем: о чем говорить, если помещения нет.
        $missing = $item->room ? Specs::check($this->requirements($item), $item->room->specs ?? []) : [];
        $shape = $this->shape($item);
        $footprint = $this->footprint($item);

        if (! $item->room) {
            $problems[] = 'Вне помещений';
        } elseif (! $item->room->polygon) {
            $notes[] = 'У помещения нет контура – место не проверить.';
        }

        if (! $shape) {
            $notes[] = 'Габариты не заданы – место не проверить.';
        } elseif ($item->room?->polygon) {
            if (! Geometry::within($shape, $item->room->polygon)) {
                $problems[] = 'Не влезает в помещение';
            } elseif (! Geometry::within($footprint, $item->room->polygon)) {
                $problems[] = 'Сервисная зона выходит за помещение';
            }
        }

        if ($shape) {
            foreach ($siblings as $other) {
                if ($other->is($item) || ($other->room_id && $item->room_id && $other->room_id !== $item->room_id)) {
                    continue;
                }
                $otherFootprint = $this->footprint($other);
                if ($otherFootprint && Geometry::overlaps($footprint, $otherFootprint)) {
                    $overlaps[] = $this->label($other);
                }
            }
            foreach ($obstacles as $obstacle) {
                if (Geometry::overlaps($footprint, $obstacle['polygon'])) {
                    $overlaps[] = $obstacle['label'];
                }
            }
        }

        if ($overlaps) {
            $problems[] = 'Пересекается с: '.implode(', ', $overlaps);
        }
        foreach ($missing as $what) {
            $problems[] = 'Помещению не хватает: '.$what;
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'missing' => $missing, 'overlaps' => $overlaps, 'notes' => $notes];
    }

    /**
     * Утвердить вариант: позиции переносятся в текущее размещение через AssetMover.
     * Единица из позиции переставляется, модель или потребность – заводится новой
     * единицей филиала и ставится на место. В перемещении остается ссылка на вариант.
     *
     * @return array{moved: int, created: int, skipped: int}
     */
    public function approve(Layout $layout, AssetMover $mover): array
    {
        $this->ensureDraft($layout);
        $layout->load('floor.building');
        if ($layout->floor->building->is_candidate) {
            throw ValidationException::withMessages([
                'layout' => 'Это здание проекта, а не настоящее: сначала выберите его в проекте, потом утверждайте вариант.',
            ]);
        }
        $officeId = $layout->floor->building->office_id;
        if (! $officeId) {
            throw ValidationException::withMessages(['layout' => 'У здания нет филиала – переносить единицы некуда.']);
        }

        return DB::transaction(function () use ($layout, $mover, $officeId) {
            $done = ['moved' => 0, 'created' => 0, 'skipped' => 0];
            $comment = "Утвержден вариант \"{$layout->name}\"";

            foreach ($layout->items()->with(['asset', 'model', 'need.model'])->orderBy('id')->get() as $item) {
                $asset = $item->asset;
                if (! $asset) {
                    $asset = $this->createAsset($item, $officeId);
                    if (! $asset) {
                        $done['skipped']++;
                        continue;
                    }
                    // Единица создана по этой позиции: второе утверждение ее не задвоит.
                    $item->update(['asset_id' => $asset->id]);
                    $done['created']++;
                } else {
                    $done['moved']++;
                }

                $mover->move($asset, [
                    'floor_id' => $layout->floor_id,
                    'room_id' => $item->room_id,
                    'x' => $item->x,
                    'y' => $item->y,
                    'rotation' => $item->rotation,
                ], $comment, $layout->id);
            }

            $layout->update(['status' => LayoutStatus::Approved, 'approved_at' => now(), 'approved_by' => Auth::id()]);

            return $done;
        });
    }

    /** Название позиции для списков и сообщений. */
    public function label(LayoutItem $item): string
    {
        return $item->label
            ?: ($item->asset?->title ?? $item->need?->label ?? $item->model?->title ?? 'Позиция');
    }

    /** Требования к помещению: потребности проекта (у нее свои или модельные) или модели позиции. */
    public function requirements(LayoutItem $item): array
    {
        return $item->need
            ? $item->need->effectiveRequirements()
            : ($item->effectiveModel()?->requirements ?? []);
    }

    /** Контур самого аппарата, м. Пусто – габариты неизвестны. */
    public function shape(LayoutItem $item): ?array
    {
        [$width, $depth] = $this->size($item);

        return $width && $depth ? Geometry::footprint((float) $item->x, (float) $item->y, (float) $item->rotation, $width, $depth) : null;
    }

    /** След с сервисными зонами, м; без габаритов – сама точка. */
    public function footprint(LayoutItem $item): ?array
    {
        [$width, $depth] = $this->size($item);

        return $width && $depth
            ? Geometry::footprint((float) $item->x, (float) $item->y, (float) $item->rotation, $width, $depth, $this->clearance($item))
            : null;
    }

    /** Габариты позиции, мм: модели или примерные из потребности проекта. */
    private function size(LayoutItem $item): array
    {
        $model = $item->effectiveModel();
        if ($model?->width_mm && $model->depth_mm) {
            return [(int) $model->width_mm, (int) $model->depth_mm];
        }

        return [(int) ($item->need?->width_mm ?? 0), (int) ($item->need?->depth_mm ?? 0)];
    }

    private function clearance(LayoutItem $item): array
    {
        return $item->effectiveModel()?->clearance ?? [];
    }

    /** Позиция для экрана и отчета. */
    private function describe(LayoutItem $item, Collection $items, array $obstacles): array
    {
        $model = $item->effectiveModel();
        [$width, $depth] = $this->size($item);

        return [
            'id' => $item->id,
            'label' => $this->label($item),
            // Свое название позиции (не унаследованное) – его и правят в карточке.
            'labelOwn' => $item->label,
            'kind' => $item->asset_id ? 'asset' : ($item->need_id ? 'need' : 'model'),
            'assetId' => $item->asset_id,
            'modelId' => $item->model_id,
            'needId' => $item->need_id,
            'model' => $model?->title,
            'x' => (float) $item->x,
            'y' => (float) $item->y,
            'rotation' => (float) $item->rotation,
            'width' => $width ?: null,
            'depth' => $depth ?: null,
            'room' => $item->room?->title,
            'roomId' => $item->room_id,
            'shape' => $this->shape($item),
            'footprint' => $this->footprint($item),
            'url' => $item->asset_id ? route('assets.show', $item->asset_id) : null,
            'fit' => $this->fit($item, $items, $obstacles),
        ];
    }

    /**
     * Что уже стоит на этаже и мешает: единицы с габаритами, кроме тех, которые этот
     * вариант сам и переставляет.
     *
     * @param  Collection<int, LayoutItem>  $items
     * @return list<array{label: string, polygon: list<array{0: float, 1: float}>}>
     */
    private function obstacles(Layout $layout, Collection $items): array
    {
        $moved = $items->pluck('asset_id')->filter()->all();

        return Asset::where('floor_id', $layout->floor_id)
            ->whereNotNull('x')->whereNotIn('id', $moved)
            ->where('status', '!=', AssetStatus::WrittenOff)
            ->whereHas('model', fn ($q) => $q->whereNotNull('width_mm')->whereNotNull('depth_mm'))
            ->with(['model', 'category'])->get()
            ->map(fn (Asset $asset) => [
                'label' => $asset->title.' (стоит)',
                'polygon' => Geometry::footprint((float) $asset->x, (float) $asset->y, (float) ($asset->rotation ?? 0),
                    (int) $asset->model->width_mm, (int) $asset->model->depth_mm, $asset->model->clearance ?? []),
            ])->all();
    }

    /** Новая единица по позиции: модель из справочника или потребность проекта. */
    private function createAsset(LayoutItem $item, int $officeId): ?Asset
    {
        $model = $item->model ?? $item->need?->model;
        $categoryId = $model?->category_id ?? $item->need?->category_id;
        if (! $categoryId) {
            return null;
        }

        return Asset::create([
            'category_id' => $categoryId,
            'model_id' => $model?->id,
            'name' => $item->label ?: ($item->need?->label ?? $model?->name),
            'office_id' => $officeId,
        ]);
    }

    /** Помещение по точке: позиции ставятся в то, в контур которого попали. */
    private function roomAt(Layout $layout, array $data): ?int
    {
        return $this->plans->roomAt($layout->floor, (float) $data['x'], (float) $data['y'])?->id;
    }

    private function ensureDraft(Layout $layout): void
    {
        if ($layout->status !== LayoutStatus::Draft) {
            throw ValidationException::withMessages(['layout' => "Вариант уже не черновик: {$layout->status->label()}."]);
        }
    }

    /** Модели для выпадающего списка "что ставим". */
    public static function modelOptions(): Collection
    {
        return AssetModel::with('category.layer')->orderBy('manufacturer')->orderBy('name')->get();
    }
}
