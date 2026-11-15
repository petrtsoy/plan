@extends('layouts.app')
@section('title', 'ТО и поверка')
@section('content')
@php
    use App\Services\Maintenance;
    $badges = ['overdue' => 'bad', 'soon' => 'warn', 'ok' => 'ok', 'none' => 'mute'];
@endphp

<div class="head">
    <h1>Что пора обслуживать</h1>
    <div class="actions" style="margin:0">
        <a class="btn" href="{{ route('maintenance.records') }}">Журнал выполнения</a>
        <a class="btn" href="{{ route('contractors.index') }}">Сервисные организации</a>
    </div>
</div>
<p class="muted small">
    Состояния и пороги – те же, что раскрашивают единицы на плане этажа: просрочено,
    срок в ближайшие {{ $days }} дней, в порядке, регламента нет.
</p>

<div class="tabs">
    <a href="{{ route('maintenance.index', collect($filters)->except('state')->all()) }}" @class(['on' => empty($filters['state'])])>
        Все {{ array_sum($counts) }}
    </a>
    @foreach(Maintenance::STATES as $state => $label)
        <a href="{{ route('maintenance.index', collect($filters)->except('page')->put('state', $state)->all()) }}"
           @class(['on' => ($filters['state'] ?? null) === $state])>{{ $label }} {{ $counts[$state] }}</a>
    @endforeach
</div>

<form class="filters" method="get">
    <select name="office_id">
        <option value="">Все филиалы</option>
        @foreach($offices as $o)<option value="{{ $o->id }}" @selected((int) ($filters['office_id'] ?? 0) === $o->id)>{{ $o->display_name }}</option>@endforeach
    </select>
    <select name="contractor_id">
        <option value="">Все организации</option>
        @foreach($contractors as $c)<option value="{{ $c->id }}" @selected((int) ($filters['contractor_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach
    </select>
    <select name="layer_id">
        <option value="">Все слои</option>
        @foreach($layers as $l)<option value="{{ $l->id }}" @selected((int) ($filters['layer_id'] ?? 0) === $l->id)>{{ $l->name }}</option>@endforeach
    </select>
    <select name="kind">
        <option value="">Все виды работ</option>
        @foreach($kinds as $v => $label)<option value="{{ $v }}" @selected(($filters['kind'] ?? null) === $v)>{{ $label }}</option>@endforeach
    </select>
    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Название или инв. №">
    @if($filters['state'] ?? null)<input type="hidden" name="state" value="{{ $filters['state'] }}">@endif
    <button class="btn">Показать</button>
    @if($filters)<a class="btn link" href="{{ route('maintenance.index') }}">Сбросить</a>@endif
</form>

<div class="table-wrap"><table>
    <tr><th>Срок</th><th>Единица</th><th>Место</th><th>Регламент</th><th>Организация</th></tr>
    @forelse($assets as $asset)
        @php
            $state = Maintenance::state($asset->due_at, $days);
            $plans = $asset->maintenancePlans;
        @endphp
        <tr>
            <td class="nowrap">
                @if($asset->due_at)
                    <span class="badge {{ $badges[$state] }}">{{ \Illuminate\Support\Carbon::parse($asset->due_at)->format('d.m.Y') }}</span>
                @else
                    <span class="badge mute">{{ Maintenance::STATES['none'] }}</span>
                @endif
            </td>
            <td>
                <a href="{{ route('maintenance.asset', $asset) }}">{{ $asset->title }}</a>
                <div class="small muted">{{ $asset->inventory_number ?? 'без инв. №' }} · {{ $asset->category->layer->name }} / {{ $asset->category->name }}</div>
            </td>
            <td class="small">
                {{ $asset->office?->display_name ?? '–' }}
                <div class="muted">
                    @if($asset->floor)<a href="{{ route('floors.show', $asset->floor) }}">{{ $asset->floor->path }}</a>@else не расставлено @endif
                    {{ $asset->room ? ' / '.$asset->room->title : '' }}
                </div>
            </td>
            <td class="small">
                @forelse($plans as $plan)
                    <div>
                        {{ $plan->kind->label() }} – раз в {{ $plan->interval_months }} мес.,
                        <span class="muted">{{ $plan->next_due_at ? 'срок '.$plan->next_due_at->format('d.m.Y') : 'срока нет' }}</span>
                    </div>
                @empty
                    <span class="muted">не заведен</span>
                @endforelse
            </td>
            <td class="small">{{ $plans->pluck('contractor.name')->filter()->unique()->implode(', ') ?: ($asset->contractor?->name ?? '–') }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">Ничего не нашлось.</td></tr>
    @endforelse
</table></div>
@include('partials.pager', ['paginator' => $assets])
@endsection
