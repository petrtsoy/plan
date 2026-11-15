<?php

namespace App\Http\Controllers;

use App\Enums\MaintenanceKind;
use App\Enums\RiskClass;
use App\Models\AssetModel;
use App\Models\Category;
use App\Services\Specs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Справочник моделей: габариты и сервисные зоны – для плана и примерки, требования
 * к помещению (каталог Specs) – для проверки "хватает ли помещению", регламент – для ТО.
 */
class AssetModelController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $categoryId = $request->integer('category_id');

        $models = AssetModel::with('category.layer')->withCount('assets')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('manufacturer', 'like', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('manufacturer')->orderBy('name')
            ->paginate(50)->withQueryString();

        return view('models.index', [
            'models' => $models,
            'categories' => $this->categories(),
            'search' => $search,
            'categoryId' => $categoryId,
        ]);
    }

    public function create(): View
    {
        return view('models.form', ['model' => new AssetModel, 'categories' => $this->categories()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $model = AssetModel::create($this->validated($request));

        return redirect()->route('models.edit', $model)->with('ok', 'Модель добавлена.');
    }

    public function edit(AssetModel $model): View
    {
        $model->loadCount('assets');

        return view('models.form', ['model' => $model, 'categories' => $this->categories()]);
    }

    public function update(Request $request, AssetModel $model): RedirectResponse
    {
        $model->update($this->validated($request));

        return back()->with('ok', 'Сохранено.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'manufacturer' => ['nullable', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'],
            'width_mm' => ['nullable', 'integer', 'between:1,100000'],
            'depth_mm' => ['nullable', 'integer', 'between:1,100000'],
            'height_mm' => ['nullable', 'integer', 'between:1,100000'],
            'weight_kg' => ['nullable', 'numeric', 'between:0,100000'],
            'clearance' => ['array'],
            'clearance.*' => ['nullable', 'integer', 'between:0,100000'],
            'requirements' => ['array'],
            'intervals' => ['array'],
            'intervals.*' => ['nullable', 'integer', 'between:1,600'],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'risk_class' => ['nullable', 'string', 'in:'.implode(',', array_keys(RiskClass::options()))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['clearance'] = array_filter(array_map('intval', array_intersect_key($data['clearance'] ?? [],
            array_flip(['front', 'back', 'left', 'right'])))) ?: null;
        $data['requirements'] = Specs::values($data['requirements'] ?? []) ?: null;
        $data['intervals'] = array_filter(array_map('intval', array_intersect_key($data['intervals'] ?? [],
            array_flip(array_keys(MaintenanceKind::options()))))) ?: null;

        return $data;
    }

    /** @return \Illuminate\Support\Collection<int, Category> */
    private function categories()
    {
        return Category::with('layer')->orderBy('layer_id')->orderBy('sort')->orderBy('name')->get();
    }
}
