@extends('layouts.app')
@section('title', $floor->path)
@section('main_class', 'wide')
@push('head')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
@endpush
@section('content')
<div class="floor">
    <div class="floor-map" id="map">
        @unless($floor->plan)
            <div class="floor-empty">
                <p><strong>Чертежа еще нет.</strong></p>
                <p class="muted small">Загрузите PDF, скан или фото этажа{{ $canPlans ? ' – форма справа' : '' }}. Помещения и оборудование можно ставить и без него, но мерить – нет.</p>
            </div>
        @endunless
    </div>

    <aside class="floor-side">
        <div class="crumbs"><a href="{{ route('buildings.index') }}">Здания</a> / <a href="{{ route('buildings.show', $floor->building) }}">{{ $floor->building->name }}</a></div>
        <h1 class="floor-title">{{ $floor->name }}</h1>
        @if($floor->plan)
            <p class="small muted">
                {{ $floor->plan->source->label() }}: {{ $floor->plan->source_name }}
                @if($floor->plan->isCalibrated())<span class="badge ok">масштаб задан</span>@else<span class="badge warn">масштаб не задан</span>@endif
            </p>
        @endif

        @if($canPlans || $canAssets)
            <div class="tools">
                @if($canPlans && $floor->plan)<button type="button" class="btn" data-tool="calibrate" title="Две точки известного отрезка и его длина или помещение с известной площадью">Масштаб</button>@endif
                @if($canPlans && $floor->plan)<button type="button" class="btn" data-tool="crop" title="Оставить от листа только здание: рамка по двум углам">Обрезать</button>@endif
                @if($canPlans)<button type="button" class="btn" data-tool="room" title="Обвести контур по точкам">Помещение</button>@endif
                @if($canAssets)<button type="button" class="btn" data-tool="place" title="Поставить единицу на план">Поставить</button>@endif
            </div>
        @endif
        <div class="hint" id="hint" hidden></div>
        <div id="panel"></div>

        <section>
            <h2>Слои</h2>
            <div class="layer-list">
                <label class="check"><input type="checkbox" data-rooms checked> Помещения</label>
                @foreach($map['layers'] as $l)
                    <label class="check"><input type="checkbox" data-layer="{{ $l->id }}" @checked($l->visible)> <span class="dot" style="background:{{ $l->color }}"></span> {{ $l->name }}</label>
                @endforeach
            </div>
            <h2>Цвет</h2>
            <div class="layer-list">
                <label class="check"><input type="radio" name="color" value="layer" checked> по слою</label>
                <label class="check"><input type="radio" name="color" value="due"> по сроку ТО и поверки</label>
            </div>
            <div class="legend small muted" id="legend-due" hidden>
                <span><span class="dot due-overdue"></span> просрочено</span>
                <span><span class="dot due-soon"></span> в ближайшие {{ config('services.alerts.maintenance_days') }} дн.</span>
                <span><span class="dot due-ok"></span> в порядке</span>
                <span><span class="dot due-none"></span> нет регламента</span>
            </div>
        </section>

        @if($canPlans)
            <details class="side-block" @if(!$floor->plan) open @endif>
                <summary><strong>{{ $floor->plan ? 'Заменить чертеж' : 'Загрузить чертеж' }}</strong></summary>
                <form method="post" action="{{ route('floors.plans.store', $floor) }}" enctype="multipart/form-data">
                    @csrf
                    <label>Файл: PDF, PNG, JPG, WebP до 64 МБ</label>
                    <input type="file" name="file" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                    <label>Страница PDF</label>
                    <input type="number" name="page" min="1" value="1" class="short">
                    @if($floor->plan)<p class="small muted">Расставленное останется в тех же метрах; новую подложку подгоните масштабом.</p>@endif
                    <div class="actions"><button class="btn primary">Загрузить</button></div>
                </form>
            </details>
            @if($floor->plan?->source === \App\Enums\PlanSource::Pdf)
                <details class="side-block">
                    <summary><strong>Импорт из проекта</strong></summary>
                    <p class="small muted">Подложка – лист {{ $floor->plan->page }} файла {{ $floor->plan->source_name }}. Помещения берутся из экспликации, мебель и оборудование – по меткам (М1, Э1…) листа расстановки и спецификации. Листы – из того же файла.</p>
                    <label>Лист расстановки</label><input type="number" id="imp-marks" min="1" class="short">
                    <label>Лист спецификации</label><input type="number" id="imp-spec" min="1" class="short">
                    <p class="small muted">Пусто – только помещения.</p>
                    <div class="actions"><button type="button" class="btn" id="imp-go">Разобрать</button></div>
                    <div id="imp-result"></div>
                </details>
            @endif
            <details class="side-block">
                <summary><strong>Этаж</strong></summary>
                <form method="post" action="{{ route('floors.update', $floor) }}">
                    @csrf @method('put')
                    <label>Номер</label><input name="level" type="number" value="{{ $floor->level }}" required>
                    <label>Название</label><input name="name" value="{{ $floor->name }}" required>
                    <label>Высота, м</label><input name="height_m" type="number" step="0.01" value="{{ $floor->height_m }}">
                    <div class="actions"><button class="btn primary">Сохранить</button></div>
                </form>
            </details>
        @endif
    </aside>
</div>

<template id="tpl-categories">
    @foreach($categories->groupBy(fn ($c) => $c->layer->name) as $layer => $cats)
        <optgroup label="{{ $layer }}">@foreach($cats as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</optgroup>
    @endforeach
</template>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
window.FLOOR = @json($map + ['floorId' => $floor->id, 'canPlans' => $canPlans, 'canAssets' => $canAssets]);
</script>
<script src="{{ asset('js/floor.js') }}?v={{ filemtime(public_path('js/floor.js')) }}"></script>
@endpush
