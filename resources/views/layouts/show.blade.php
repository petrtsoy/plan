@extends('layouts.app')
@section('title', $layout->name.' – '.$floor->path)
@section('main_class', 'wide')
@push('head')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
@endpush
@section('content')
<div class="floor">
    <div class="floor-map" id="map">
        @unless($floor->plan)
            <div class="floor-empty">
                <p><strong>Чертежа этажа еще нет.</strong></p>
                <p class="muted small">Ставить оборудование можно и так – по метрам и контурам помещений, – но смотреть удобнее на подложке.</p>
            </div>
        @endunless
    </div>

    <aside class="floor-side">
        <div class="crumbs">
            @if($floor->building->is_candidate && $floor->building->project)
                <a href="{{ route('projects.show', $floor->building->project) }}">{{ $floor->building->project->name }}</a>
            @else
                <a href="{{ route('buildings.show', $floor->building) }}">{{ $floor->building->name }}</a>
            @endif
            / <a href="{{ route('floors.layouts.index', $floor) }}">варианты</a>
        </div>
        <h1 class="floor-title">{{ $layout->name }}</h1>
        <p class="small muted">
            {{ $floor->path }} ·
            @if($layout->status === \App\Enums\LayoutStatus::Approved)<span class="badge ok">{{ $layout->status->label() }}</span>
            @elseif($layout->status === \App\Enums\LayoutStatus::Archived)<span class="badge mute">{{ $layout->status->label() }}</span>
            @else<span class="badge">{{ $layout->status->label() }}</span>@endif
            @if($layout->project) · проект <a href="{{ route('projects.show', $layout->project) }}">{{ $layout->project->name }}</a>@endif
        </p>
        @if($floor->plan && !$floor->plan->isCalibrated())
            <p class="small"><span class="badge warn">масштаб не задан</span> Пока метр плана – пиксель картинки: габариты
                и сервисные зоны считаются не в тех метрах. Задайте масштаб на <a href="{{ route('floors.show', $floor) }}">плане этажа</a>.</p>
        @endif

        @if($canEdit)
            <div class="tools">
                <button type="button" class="btn" data-tool="place" title="Выбрать, что ставим, и щелкнуть по месту на плане">Поставить</button>
            </div>
        @endif
        <div class="hint" id="hint" hidden></div>
        <div id="panel"></div>

        <section>
            <h2>Позиции <span class="muted" id="sum-count"></span></h2>
            <div id="items" class="items"></div>
        </section>

        @if($layout->project)
            <section>
                <h2>Потребности проекта</h2>
                <div id="needs" class="layer-list small"></div>
            </section>
        @endif

        <section>
            <h2>Показывать</h2>
            <div class="layer-list">
                <label class="check"><input type="checkbox" data-show="rooms" checked> Помещения</label>
                <label class="check"><input type="checkbox" data-show="standing" checked> Что уже стоит на этаже</label>
                <label class="check"><input type="checkbox" data-show="clearance" checked> Сервисные зоны</label>
            </div>
            <div class="legend small muted">
                <span><span class="dot" style="background:#0d9488"></span> влезает</span>
                <span><span class="dot" style="background:#dc2626"></span> не влезает или не хватает помещению</span>
                <span><span class="dot" style="background:#94a3b8"></span> уже стоит</span>
            </div>
        </section>

        @if($canApprove)
            <form method="post" action="{{ route('layouts.approve', $layout) }}" class="side-block"
                  onsubmit="return confirm('Утвердить вариант? Единицы переставятся на эти места, новые – заведутся в филиале. Отменить это можно только обратным перемещением.')">
                @csrf
                <strong>Утвердить вариант</strong>
                <p class="small muted">Существующие единицы переставятся через историю перемещений (в ней останется
                ссылка на вариант), модели и потребности заведутся новыми единицами филиала и встанут на свои места.</p>
                <div class="actions"><button class="btn primary">Утвердить</button></div>
            </form>
        @elseif($floor->building->is_candidate)
            <div class="side-block small muted">Это здание проекта: утверждать вариант можно только после того,
                как здание выберут в проекте – до этого настоящих единиц в нем не бывает.</div>
        @endif

        @if($canEdit)
            <details class="side-block">
                <summary><strong>Вариант</strong></summary>
                <form method="post" action="{{ route('layouts.update', $layout) }}">
                    @csrf @method('put')
                    <label>Название</label><input name="name" value="{{ $layout->name }}" required>
                    <label>Заметки</label><textarea name="notes" rows="3">{{ $layout->notes }}</textarea>
                    <label>Состояние</label>
                    <select name="status">
                        <option value="draft" @selected($layout->status === \App\Enums\LayoutStatus::Draft)>Черновик</option>
                        <option value="archived" @selected($layout->status === \App\Enums\LayoutStatus::Archived)>В архиве</option>
                    </select>
                    <div class="actions"><button class="btn primary">Сохранить</button></div>
                </form>
            </details>
        @endif
    </aside>
</div>

<template id="tpl-models">
    @foreach($models->groupBy(fn ($m) => $m->category->name) as $category => $group)
        <optgroup label="{{ $category }}">
            @foreach($group as $m)
                <option value="{{ $m->id }}">{{ $m->title }}@if($m->width_mm && $m->depth_mm) · {{ $m->width_mm }}×{{ $m->depth_mm }}@else · без габаритов @endif</option>
            @endforeach
        </optgroup>
    @endforeach
</template>
<template id="tpl-assets">
    @foreach($unplaced as $a)
        <option value="{{ $a->id }}">{{ $a->title }}@if($a->inventory_number) · {{ $a->inventory_number }}@endif</option>
    @endforeach
</template>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
window.LAYOUT = @json($map);
</script>
<script src="{{ asset('js/layout.js') }}?v={{ filemtime(public_path('js/layout.js')) }}"></script>
@endpush
