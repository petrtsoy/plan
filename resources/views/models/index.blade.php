@extends('layouts.app')
@section('title', 'Модели оборудования')
@section('content')
@php($canEdit = auth()->user()->edits(\App\Enums\Area::Assets))
<div class="head">
    <h1>Модели оборудования</h1>
    @if($canEdit)<a class="btn primary" href="{{ route('models.create') }}">Добавить модель</a>@endif
</div>
<p class="muted small">Общее для всех экземпляров: габариты и сервисные зоны – для плана и примерки,
требования к помещению – чтобы проверять, хватает ли ему мощности, слива и вентиляции,
регламент – чтобы заводить сроки ТО и поверки новым единицам.</p>

<form method="get" class="filters">
    <input name="q" value="{{ $search }}" placeholder="Производитель или название">
    <select name="category_id">
        <option value="">Все категории</option>
        @foreach($categories->groupBy(fn ($c) => $c->layer->name) as $layer => $cats)
            <optgroup label="{{ $layer }}">
                @foreach($cats as $c)<option value="{{ $c->id }}" @selected($categoryId === $c->id)>{{ $c->name }}</option>@endforeach
            </optgroup>
        @endforeach
    </select>
    <button class="btn">Показать</button>
    @if($search || $categoryId)<a class="btn link" href="{{ route('models.index') }}">Сбросить</a>@endif
</form>

<div class="table-wrap"><table>
    <tr>
        <th>Модель</th><th>Категория</th><th>Габариты, мм</th><th>Сервисные зоны, мм</th>
        <th>Требования к помещению</th><th>Единиц</th>
    </tr>
    @forelse($models as $m)
        <tr>
            <td>
                @if($canEdit)<a href="{{ route('models.edit', $m) }}">{{ $m->title }}</a>@else{{ $m->title }}@endif
                @if($m->country)<div class="small muted">{{ $m->country }}</div>@endif
            </td>
            <td>{{ $m->category->name }}<div class="small muted">{{ $m->category->layer->name }}</div></td>
            <td class="nowrap">
                @if($m->width_mm && $m->depth_mm)
                    {{ $m->width_mm }} × {{ $m->depth_mm }}@if($m->height_mm) × {{ $m->height_mm }}@endif
                    @if($m->weight_kg)<div class="small muted">{{ (float) $m->weight_kg }} кг</div>@endif
                @else
                    <span class="badge warn">не заданы</span>
                @endif
            </td>
            <td class="small nowrap">
                @php($c = $m->clearance ?? [])
                @if($c)
                    @foreach(['front' => 'перед', 'back' => 'зад', 'left' => 'слева', 'right' => 'справа'] as $k => $l)
                        @if($c[$k] ?? null)<div>{{ $l }}: {{ $c[$k] }}</div>@endif
                    @endforeach
                @else
                    <span class="muted">–</span>
                @endif
            </td>
            <td class="small">
                @php($specs = \App\Services\Specs::summary($m->requirements ?? []))
                @if($specs)<div class="tag-list">@foreach($specs as $s)<span class="tag">{{ $s }}</span>@endforeach</div>
                @else<span class="muted">–</span>@endif
            </td>
            <td>{{ $m->assets_count }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Моделей пока нет.@if($canEdit) Добавьте первую – без габаритов примерка не работает.@endif</td></tr>
    @endforelse
</table>
@include('partials.pager', ['paginator' => $models])
</div>
@endsection
