@extends('layouts.app')
@section('title', 'Проекты')
@section('content')
<h1>Проекты</h1>
<p class="muted small">Рассматриваем несколько зданий – под аренду, покупку или переезд – и примеряем
в них оборудование, пока ничего не куплено. Итог: одно здание выбрано (оно становится настоящим
зданием филиала) или не выбрано ни одного.</p>

<div class="table-wrap"><table>
    <tr><th>Проект</th><th>Филиал</th><th>Кандидатов</th><th>Потребностей</th><th>Вариантов</th><th>Итог</th></tr>
    @forelse($projects as $p)
        <tr>
            <td>
                <a href="{{ route('projects.show', $p) }}">{{ $p->name }}</a>
                @if($p->goal)<div class="small muted">{{ \Illuminate\Support\Str::limit($p->goal, 90) }}</div>@endif
            </td>
            <td>{{ $p->office?->display_name ?? '–' }}</td>
            <td>{{ $p->buildings_count }}</td>
            <td>{{ $p->needs_count }}</td>
            <td>{{ $p->layouts_count }}</td>
            <td>
                @if($p->status === \App\Enums\ProjectStatus::Chosen)
                    <span class="badge ok">{{ $p->chosenBuilding?->name }}</span>
                @elseif($p->status === \App\Enums\ProjectStatus::Rejected)
                    <span class="badge mute">{{ $p->status->label() }}</span>
                @else
                    <span class="badge">{{ $p->status->label() }}</span>
                @endif
                @if($p->decided_at)<div class="small muted">{{ $p->decided_at->format('d.m.Y') }}</div>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Проектов пока нет.</td></tr>
    @endforelse
</table></div>

@if($canEdit)
    <h2>Новый проект</h2>
    <form method="post" action="{{ route('projects.store') }}" class="panel">
        @csrf
        <div class="fields">
            <div><label>Название</label><input name="name" value="{{ old('name') }}" placeholder="Новая лаборатория в Астане" required></div>
            <div>
                <label>Филиал</label>
                <select name="office_id">
                    <option value="">филиала еще нет (новый город)</option>
                    @foreach($offices as $o)<option value="{{ $o->id }}" @selected(old('office_id') == $o->id)>{{ $o->display_name }}</option>@endforeach
                </select>
            </div>
            <div class="field-full"><label>Зачем</label><textarea name="goal" rows="2">{{ old('goal') }}</textarea></div>
        </div>
        <div class="actions"><button class="btn primary">Создать</button></div>
    </form>
@endif
@endsection
