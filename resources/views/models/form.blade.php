@extends('layouts.app')
@section('title', $model->exists ? $model->title : 'Новая модель')
@section('content')
<div class="crumbs"><a href="{{ route('models.index') }}">Модели оборудования</a></div>
<h1>{{ $model->exists ? $model->title : 'Новая модель' }}</h1>
@if($model->exists)
    <p class="muted small">{{ $model->category->layer->name }} / {{ $model->category->name }} ·
        единиц по этой модели: {{ $model->assets_count }}</p>
@endif

<form method="post" action="{{ $model->exists ? route('models.update', $model) : route('models.store') }}">
    @csrf @if($model->exists) @method('put') @endif

    <div class="panel">
        <h2>Что это</h2>
        <div class="fields">
            <div>
                <label>Категория</label>
                <select name="category_id" required>
                    @foreach($categories->groupBy(fn ($c) => $c->layer->name) as $layer => $cats)
                        <optgroup label="{{ $layer }}">
                            @foreach($cats as $c)<option value="{{ $c->id }}" @selected(old('category_id', $model->category_id) == $c->id)>{{ $c->name }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div><label>Производитель</label><input name="manufacturer" value="{{ old('manufacturer', $model->manufacturer) }}" placeholder="Sysmex"></div>
            <div><label>Название</label><input name="name" value="{{ old('name', $model->name) }}" placeholder="XN-1000" required></div>
            <div><label>Страна</label><input name="country" value="{{ old('country', $model->country) }}"></div>
            <div><label>Рег. удостоверение</label><input name="registration_number" value="{{ old('registration_number', $model->registration_number) }}"></div>
            <div>
                <label>Класс риска</label>
                <select name="risk_class">
                    <option value="">не указан</option>
                    @foreach(\App\Enums\RiskClass::options() as $v => $l)
                        <option value="{{ $v }}" @selected((string) old('risk_class', $model->risk_class?->value) === (string) $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>Габариты и сервисные зоны</h2>
        <p class="muted small">Габариты – в миллиметрах: ширина по x, глубина по y. Сервисная зона –
        свободное место для обслуживания и подхода с каждой стороны; перед аппарата смотрит вниз,
        поворот на плане разворачивает и зоны. По этому прямоугольнику примерка и считает,
        влезает ли аппарат в помещение и не налезает ли на соседнее.</p>
        <div class="fields">
            <div><label>Ширина, мм</label><input type="number" name="width_mm" min="1" value="{{ old('width_mm', $model->width_mm) }}"></div>
            <div><label>Глубина, мм</label><input type="number" name="depth_mm" min="1" value="{{ old('depth_mm', $model->depth_mm) }}"></div>
            <div><label>Высота, мм</label><input type="number" name="height_mm" min="1" value="{{ old('height_mm', $model->height_mm) }}"></div>
            <div><label>Вес, кг</label><input type="number" step="0.1" name="weight_kg" value="{{ old('weight_kg', $model->weight_kg ? (float) $model->weight_kg : null) }}"></div>
        </div>
        <div class="fields" style="margin-top:16px">
            @foreach(['front' => 'Спереди, мм', 'back' => 'Сзади, мм', 'left' => 'Слева, мм', 'right' => 'Справа, мм'] as $key => $label)
                <div>
                    <label>{{ $label }}</label>
                    <input type="number" name="clearance[{{ $key }}]" min="0" value="{{ old('clearance.'.$key, $model->clearance[$key] ?? null) }}">
                </div>
            @endforeach
        </div>
    </div>

    <div class="panel">
        <h2>Требования к помещению</h2>
        <p class="muted small">Что помещение обязано дать. При расстановке и примерке это сверяется
        со свойствами помещения (Specs::check): чего нет или меньше нужного – то и показывается
        как нехватка. Пустое и снятое – не требуется.</p>
        @include('partials.specs-fields', ['name' => 'requirements', 'values' => $model->requirements ?? []])
    </div>

    <div class="panel">
        <h2>Регламент ТО и поверки</h2>
        <p class="muted small">Раз во сколько месяцев. По этим срокам новой единице заводится
        регламент; пусто – этот вид работ по модели не нужен.</p>
        <div class="fields">
            @foreach(\App\Enums\MaintenanceKind::options() as $kind => $label)
                <div>
                    <label>{{ $label }}, мес.</label>
                    <input type="number" name="intervals[{{ $kind }}]" min="1" max="600" value="{{ old('intervals.'.$kind, $model->intervals[$kind] ?? null) }}">
                </div>
            @endforeach
        </div>
    </div>

    <div class="panel">
        <h2>Заметки</h2>
        <textarea name="notes" rows="3" class="block">{{ old('notes', $model->notes) }}</textarea>
        <div class="actions">
            <button class="btn primary">{{ $model->exists ? 'Сохранить' : 'Добавить' }}</button>
            <a class="btn link" href="{{ route('models.index') }}">К списку</a>
        </div>
    </div>
</form>
@endsection
