@extends('layouts.app')
@section('title', 'Журнал ТО и поверок')
@section('content')
@php($resultBadges = ['ok' => 'ok', 'limited' => 'warn', 'failed' => 'bad'])

<div class="head">
    <h1>Журнал выполнения</h1>
    <div class="actions" style="margin:0">
        <a class="btn" href="{{ route('maintenance.index') }}">Что пора обслуживать</a>
        <a class="btn" href="{{ route('contractors.index') }}">Сервисные организации</a>
    </div>
</div>
<p class="muted small">Выполненные ТО, поверки и прочие работы с актами и свидетельствами. Запись заводится в карточке обслуживания единицы.</p>

<form class="filters" method="get">
    <select name="office_id">
        <option value="">Все филиалы</option>
        @foreach($offices as $o)<option value="{{ $o->id }}" @selected((int) ($filters['office_id'] ?? 0) === $o->id)>{{ $o->display_name }}</option>@endforeach
    </select>
    <select name="contractor_id">
        <option value="">Все организации</option>
        @foreach($contractors as $c)<option value="{{ $c->id }}" @selected((int) ($filters['contractor_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach
    </select>
    <select name="kind">
        <option value="">Все виды работ</option>
        @foreach($kinds as $v => $label)<option value="{{ $v }}" @selected(($filters['kind'] ?? null) === $v)>{{ $label }}</option>@endforeach
    </select>
    <select name="result">
        <option value="">Любой результат</option>
        @foreach($results as $v => $label)<option value="{{ $v }}" @selected(($filters['result'] ?? null) === $v)>{{ $label }}</option>@endforeach
    </select>
    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" title="С даты">
    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" title="По дату">
    <button class="btn">Показать</button>
    @if($filters)<a class="btn link" href="{{ route('maintenance.records') }}">Сбросить</a>@endif
</form>

<div class="table-wrap"><table>
    <tr><th>Когда</th><th>Единица</th><th>Что</th><th>Результат</th><th>Действует до</th><th>Организация</th><th>Документ</th><th>Кто записал</th></tr>
    @forelse($records as $record)
        <tr>
            <td class="nowrap">{{ $record->performed_at->format('d.m.Y') }}</td>
            <td>
                <a href="{{ route('maintenance.asset', $record->asset) }}">{{ $record->asset->title }}</a>
                <div class="small muted">{{ $record->asset->inventory_number ?? 'без инв. №' }}
                    · {{ collect([$record->asset->office?->display_name, $record->asset->room?->title])->filter()->implode(' / ') ?: 'место не указано' }}</div>
            </td>
            <td>{{ $record->kind->label() }}</td>
            <td><span class="badge {{ $resultBadges[$record->result->value] }}">{{ $record->result->label() }}</span></td>
            <td class="nowrap">{{ $record->valid_until?->format('d.m.Y') ?? '–' }}</td>
            <td class="small">{{ $record->contractor?->name ?? '–' }}</td>
            <td class="small">
                {{ $record->document_number ?? '–' }}
                @foreach($record->attachments as $file)
                    <div><a href="{{ route('files.show', $file) }}">{{ $file->name }}</a></div>
                @endforeach
            </td>
            <td class="small">{{ $record->user?->name ?? 'система' }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">Записей нет.</td></tr>
    @endforelse
</table></div>
@include('partials.pager', ['paginator' => $records])
@endsection
