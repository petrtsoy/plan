@extends('layouts.app')
@section('title', $building->name)
@section('content')
@php($canPlans = auth()->user()->edits(\App\Enums\Area::Plans))
<div class="crumbs"><a href="{{ route('buildings.index') }}">Здания и планы</a> / {{ $building->office?->display_name }}</div>
<h1>{{ $building->name }}</h1>
<p class="muted small">{{ $building->address }}@if($building->total_area_m2) · {{ $building->total_area_m2 }} м²@endif</p>

<div class="table-wrap"><table>
    <tr><th>Этаж</th><th>Чертеж</th><th>Помещений</th><th>Единиц на плане</th><th>Высота, м</th></tr>
    @forelse($building->floors as $f)
        <tr>
            <td><a href="{{ route('floors.show', $f) }}">{{ $f->name }}</a> <span class="muted small">({{ $f->level }})</span></td>
            <td>
                @if(!$f->plan)<span class="badge mute">нет</span>
                @elseif($f->plan->isCalibrated())<span class="badge ok">{{ $f->plan->source->label() }}, масштаб задан</span>
                @else<span class="badge warn">{{ $f->plan->source->label() }}, без масштаба</span>@endif
            </td>
            <td>{{ $f->rooms_count }}</td>
            <td>{{ $f->assets_count }}</td>
            <td>{{ $f->height_m }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">Этажей пока нет.</td></tr>
    @endforelse
</table></div>

@if($canPlans)
    <div class="grid">
        <form method="post" action="{{ route('buildings.floors.store', $building) }}" class="panel">
            @csrf
            <h2>Добавить этаж</h2>
            <div class="fields">
                <div><label>Номер</label><input name="level" type="number" value="{{ old('level', ($building->floors->max('level') ?? 0) + 1) }}" required>
                    <div class="small muted">-1 подвал, 0 цоколь, 1, 2...</div></div>
                <div><label>Название</label><input name="name" value="{{ old('name') }}" placeholder="1 этаж" required></div>
                <div><label>Высота, м</label><input name="height_m" type="number" step="0.01" value="{{ old('height_m', 3) }}"></div>
            </div>
            <div class="actions"><button class="btn primary">Добавить</button></div>
        </form>

        <details class="panel">
            <summary><strong>Изменить здание</strong></summary>
            <form method="post" action="{{ route('buildings.update', $building) }}" style="margin-top:12px">
                @csrf @method('put')
                @include('buildings.fields', ['b' => $building, 'offices' => \App\Models\Office::where('is_active', true)->orderBy('name')->get()])
                <div class="actions"><button class="btn primary">Сохранить</button></div>
            </form>
        </details>
    </div>
@endif
@endsection
