@extends('layouts.app')
@section('title', $inventory->name)
@section('content')
@php
    $open = $inventory->status === \App\Enums\InventoryStatus::Open;
    $badge = ['found' => 'ok', 'misplaced' => 'warn', 'missing' => 'bad', 'surplus' => '', 'pending' => 'mute'];
@endphp
<div class="crumbs no-print"><a href="{{ route('inventories.index') }}">Инвентаризация</a> / {{ $inventory->office->display_name }}</div>
<div class="head">
    <div>
        <h1>{{ $inventory->name }}</h1>
        <p class="muted small">
            @if($open)<span class="badge">Идет</span>@else<span class="badge mute">Завершена {{ $inventory->closed_at?->format('d.m.Y H:i') }}</span>@endif
            {{ $inventory->order_number ? 'приказ '.$inventory->order_number : '' }}
            {{ $inventory->order_date ? 'от '.$inventory->order_date->format('d.m.Y') : '' }}
            · открыл {{ $inventory->user?->name ?? '–' }} {{ $inventory->started_at?->format('d.m.Y H:i') }}
            @if($inventory->scope['rooms'] ?? false) · только выбранные помещения ({{ count($inventory->scope['rooms']) }}) @endif
        </p>
    </div>
    <div class="actions no-print" style="margin-top:0">
        <a class="btn" href="{{ route('inventories.report', $inventory) }}">Ведомость расхождений</a>
        <a class="btn" href="{{ route('labels', ['inventory' => $inventory->id]) }}">QR-этикетки</a>
    </div>
</div>

<div class="grid">
    <div class="panel">
        <h2>Ведомость</h2>
        <p class="stat">{{ $counts['total'] }}</p>
        <p class="muted small">строк, из них не проверено {{ $counts['pending'] }}</p>
    </div>
    @foreach(['found' => 'На месте', 'misplaced' => 'Не на своем месте', 'missing' => 'Не найдено', 'surplus' => 'Излишки'] as $mark => $title)
        <div class="panel">
            <h2>{{ $title }}</h2>
            <p class="stat">{{ $counts[$mark] }}</p>
            <p class="muted small"><a href="{{ route('inventories.show', [$inventory, 'mark' => $mark]) }}">показать</a></p>
        </div>
    @endforeach
</div>

@if($counts['total'])
    {{-- С этого списка комиссия заходит в помещение с телефона. --}}
    <h2>По помещениям</h2>
    <div class="table-wrap"><table>
        <tr><th>Помещение</th><th>Этаж</th><th>Строк</th><th>Не проверено</th></tr>
        @foreach($rooms as $room)
            @continue(!isset($progress[$room->id]))
            <tr>
                <td><a href="{{ route('inventories.room', [$inventory, $room]) }}">{{ $room->title }}</a></td>
                <td class="muted small">{{ $room->floor->path }}</td>
                <td>{{ $progress[$room->id]->n }}</td>
                <td>@if($progress[$room->id]->pending)<span class="badge mute">{{ $progress[$room->id]->pending }}</span>@else<span class="badge ok">все</span>@endif</td>
            </tr>
        @endforeach
        @isset($progress[0])
            <tr><td colspan="2" class="muted">Без помещения (не расставлено)</td><td>{{ $progress[0]->n }}</td><td>{{ $progress[0]->pending ?: 'все' }}</td></tr>
        @endisset
    </table></div>
@endif

<h2>Строки ведомости</h2>
<form method="get" class="filters no-print">
    <select name="room">
        <option value="">Любое помещение</option>
        @foreach($rooms as $room)<option value="{{ $room->id }}" @selected(($filters['room'] ?? null) == $room->id)>{{ $room->title }}</option>@endforeach
    </select>
    <select name="mol">
        <option value="">Любой МОЛ</option>
        @foreach($mols as $mol)<option value="{{ $mol->id }}" @selected(($filters['mol'] ?? null) == $mol->id)>{{ $mol->full_name }}</option>@endforeach
    </select>
    <select name="mark">
        <option value="">Любая отметка</option>
        @foreach(\App\Enums\InventoryMark::options() as $value => $label)<option value="{{ $value }}" @selected(($filters['mark'] ?? null) === $value)>{{ $label }}</option>@endforeach
    </select>
    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Название или номер" maxlength="191">
    <button class="btn">Показать</button>
    @if(array_filter($filters))<a class="btn link" href="{{ route('inventories.show', $inventory) }}">Сбросить</a>@endif
</form>

<div class="table-wrap">
    <table class="inv-sheet">
        <tr>
            <th>Единица</th><th>Инв. №</th><th>Числится</th><th>МОЛ</th><th>Отметка</th><th>Нашли</th>
            @if($canCheck)<th>Отметить</th>@endif
        </tr>
        @forelse($items as $item)
            <tr data-row data-mark="{{ $item->mark->value }}">
                <td>
                    @if($item->asset)<a href="{{ route('assets.show', $item->asset) }}">{{ $item->asset->title }}</a>
                        <div class="muted small">{{ $item->asset->category->name }}</div>
                    @else<em>нет в учете</em>@endif
                    @if($item->note)<div class="muted small">{{ $item->note }}</div>@endif
                </td>
                <td class="nowrap">{{ $item->asset?->inventory_number ?? '–' }}</td>
                <td>{{ $item->expectedRoom?->title ?? ($item->asset ? 'не расставлено' : '–') }}</td>
                <td>{{ $item->expectedMol?->full_name ?? '–' }}</td>
                <td><span data-state class="badge {{ $badge[$item->mark->value] }}">{{ $item->mark->label() }}</span></td>
                <td><span data-found>{{ $item->foundRoom?->title }}</span>
                    @if($item->checked_at)<div class="muted small">{{ $item->checker?->name }} {{ $item->checked_at->format('d.m H:i') }}</div>@endif
                </td>
                @if($canCheck)
                    <td>@if($item->mark === \App\Enums\InventoryMark::Surplus)<span class="muted small">излишек</span>@else
                        @include('inventories.marks', ['url' => route('inventories.mark', [$inventory, $item])])
                    @endif</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $canCheck ? 7 : 6 }}" class="muted">Строк нет – проверьте фильтры.</td></tr>
        @endforelse
    </table>
    @include('partials.pager', ['paginator' => $items])
</div>

@if($canCheck)
    <div class="grid">
        <form class="panel" data-surplus action="{{ route('inventories.check', $inventory) }}">
            <h2>Излишек</h2>
            <p class="muted small">Нашли то, чего в ведомости нет. Если на нем есть этикетка – проще отсканировать QR: с карточки единицы отметка встанет сама.</p>
            <label>Где нашли</label>
            <select name="found_room_id">
                <option value="">не указано</option>
                @foreach($rooms as $room)<option value="{{ $room->id }}">{{ $room->title }}</option>@endforeach
            </select>
            <label>Что нашли</label>
            <input name="note" maxlength="191" placeholder="Холодильник Beko, без номера">
            <div class="actions"><button class="btn">Добавить</button></div>
        </form>

        @if($open)
            <form class="panel" method="post" action="{{ route('inventories.close', $inventory) }}"
                  onsubmit="return confirm('Закрыть инвентаризацию? Единицы, найденные не на своем месте, переедут в фактические помещения – с историей и актами передачи.')">
                @csrf
                <h2>Закрыть</h2>
                <p class="muted small">
                    По итогам места пересчитываются: строки "не на своем месте" и излишки с указанным помещением переставляются через перемещение с комментарием про инвентаризацию.
                    @if($counts['pending'])<br><strong>Не проверено строк: {{ $counts['pending'] }}</strong> – они останутся непроверенными.@endif
                </p>
                <label>Примечание к итогам</label>
                <textarea name="notes" maxlength="5000">{{ $inventory->notes }}</textarea>
                <div class="actions"><button class="btn dark">Завершить инвентаризацию</button></div>
            </form>
        @endif
    </div>
@endif

@if($canCheck)
    <template id="tpl-rooms">
        @foreach($rooms as $room)<option value="{{ $room->id }}">{{ $room->title }}</option>@endforeach
    </template>
@endif
@endsection

@push('scripts')
<script src="{{ asset('js/inventory.js') }}?v={{ filemtime(public_path('js/inventory.js')) }}"></script>
@endpush
