@extends('layouts.app')
@section('title', 'Варианты расстановки – '.$floor->path)
@section('content')
<div class="crumbs">
    @if($floor->building->is_candidate && $floor->building->project)
        <a href="{{ route('projects.show', $floor->building->project) }}">{{ $floor->building->project->name }}</a> /
    @else
        <a href="{{ route('buildings.index') }}">Здания и планы</a> /
        <a href="{{ route('buildings.show', $floor->building) }}">{{ $floor->building->name }}</a> /
    @endif
    <a href="{{ route('floors.show', $floor) }}">{{ $floor->name }}</a>
</div>
<h1>Варианты расстановки</h1>
<p class="muted small">Примерка: ставим оборудование на план, не меняя того, что стоит на самом деле.
Вариант показывает, влезает ли аппарат вместе с сервисной зоной и хватает ли помещению мощности,
слива и вентиляции. Утверждение переносит вариант в текущее размещение.</p>

<div class="table-wrap"><table>
    <tr><th>Вариант</th><th>Проект</th><th>Позиций</th><th>Влезает</th><th>Занято, м²</th><th>Автор</th><th>Состояние</th></tr>
    @forelse($rows as $row)
        @php($layout = $row['layout'])
        <tr>
            <td><a href="{{ route('layouts.show', $layout) }}">{{ $layout->name }}</a></td>
            <td>@if($layout->project)<a href="{{ route('projects.show', $layout->project) }}">{{ $layout->project->name }}</a>@else<span class="muted">–</span>@endif</td>
            <td>{{ $row['items'] }}</td>
            <td>
                @if(!$row['items'])<span class="muted">–</span>
                @elseif($row['fits'] === $row['items'])<span class="badge ok">все {{ $row['items'] }}</span>
                @else<span class="badge bad">{{ $row['fits'] }} из {{ $row['items'] }}</span>@endif
            </td>
            <td>{{ $row['area'] ?: '–' }}</td>
            <td>{{ $layout->user?->name ?? '–' }}</td>
            <td>
                @if($layout->status === \App\Enums\LayoutStatus::Approved)
                    <span class="badge ok">{{ $layout->status->label() }}</span>
                    <div class="small muted">{{ $layout->approved_at?->format('d.m.Y') }}@if($layout->approver), {{ $layout->approver->name }}@endif</div>
                @elseif($layout->status === \App\Enums\LayoutStatus::Archived)
                    <span class="badge mute">{{ $layout->status->label() }}</span>
                @else
                    <span class="badge">{{ $layout->status->label() }}</span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Вариантов пока нет.</td></tr>
    @endforelse
</table></div>

@php($withProblems = $rows->filter(fn ($r) => $r['problems']))
@if($withProblems->isNotEmpty())
    <h2>Что мешает</h2>
    <div class="grid">
        @foreach($withProblems as $row)
            <div class="panel">
                <h2><a href="{{ route('layouts.show', $row['layout']) }}">{{ $row['layout']->name }}</a></h2>
                <ul class="list-plain small">
                    @foreach($row['problems'] as $problem)<li>{{ $problem }}</li>@endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endif

@if($canEdit)
    <h2>Новый вариант</h2>
    <form method="post" action="{{ route('floors.layouts.store', $floor) }}" class="panel">
        @csrf
        <div class="fields">
            <div><label>Название</label><input name="name" value="{{ old('name', 'Вариант '.($rows->count() + 1)) }}" required></div>
            <div>
                <label>Проект</label>
                <select name="project_id">
                    <option value="">вне проекта (примерка на своем этаже)</option>
                    @foreach($projects as $p)
                        <option value="{{ $p->id }}" @selected(old('project_id', $floor->building->project_id) == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            @if($rows->isNotEmpty())
                <div>
                    <label>Взять позиции из</label>
                    <select name="copy_from">
                        <option value="">с чистого листа</option>
                        @foreach($rows as $row)<option value="{{ $row['layout']->id }}">{{ $row['layout']->name }}</option>@endforeach
                    </select>
                    <div class="small muted">Чтобы сравнить два варианта, отличающихся парой позиций.</div>
                </div>
            @endif
            <div class="field-full"><label>Заметки</label><textarea name="notes" rows="2">{{ old('notes') }}</textarea></div>
        </div>
        <div class="actions"><button class="btn primary">Создать</button></div>
    </form>
@endif
@endsection
