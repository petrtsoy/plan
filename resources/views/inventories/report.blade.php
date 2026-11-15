@extends('layouts.app')
@section('title', 'Ведомость расхождений – '.$inventory->name)
@section('content')
@php($marks = ['misplaced' => 'warn', 'missing' => 'bad', 'surplus' => '', 'pending' => 'mute'])
<div class="crumbs no-print"><a href="{{ route('inventories.show', $inventory) }}">{{ $inventory->name }}</a></div>
<div class="head no-print">
    <h1>Ведомость расхождений</h1>
    <button type="button" class="btn primary" onclick="window.print()">Печать</button>
</div>

<div class="print-doc">
    <h1 class="print-title">Ведомость расхождений по результатам инвентаризации</h1>
    <p class="print-sub">
        {{ $inventory->office->name }}<br>
        {{ $inventory->name }}@if($inventory->order_number), приказ № {{ $inventory->order_number }}@endif
        @if($inventory->order_date) от {{ $inventory->order_date->format('d.m.Y') }}@endif<br>
        Снято {{ $inventory->started_at?->format('d.m.Y') }}@if($inventory->closed_at), завершено {{ $inventory->closed_at->format('d.m.Y') }}@endif.
        Строк в ведомости {{ $counts['total'] }}, на месте {{ $counts['found'] }},
        расхождений {{ $counts['misplaced'] + $counts['missing'] + $counts['surplus'] }}@if($counts['pending']), не проверено {{ $counts['pending'] }}@endif.
    </p>

    @forelse($groups as $mol => $items)
        <h2 class="print-group">Материально ответственное лицо: {{ $mol }}</h2>
        <table class="print-table">
            <thead>
                <tr><th>№</th><th>Наименование</th><th>Инв. №</th><th>Числится</th><th>Расхождение</th><th>Фактически</th><th>Примечание</th></tr>
            </thead>
            <tbody>
                @foreach($items as $i => $item)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $item->asset?->title ?? 'нет в учете' }}@if($item->asset)<br><span class="muted small">{{ $item->asset->category->name }}</span>@endif</td>
                        <td class="nowrap">{{ $item->asset?->inventory_number ?? '–' }}</td>
                        <td>{{ $item->expectedRoom?->title ?? ($item->asset ? 'не расставлено' : '–') }}</td>
                        <td><span class="badge {{ $marks[$item->mark->value] ?? '' }}">{{ $item->mark->label() }}</span></td>
                        <td>{{ $item->foundRoom?->title ?? '–' }}</td>
                        <td>{{ $item->note }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="print-sign">{{ $mol }} _______________________ <span class="muted">(подпись)</span></p>
    @empty
        <p>Расхождений нет: все {{ $counts['found'] }} строк отмечены "на месте".</p>
    @endforelse

    <div class="print-signs">
        <p>Председатель комиссии _______________________ / _______________________</p>
        <p>Члены комиссии _______________________ / _______________________</p>
        <p>_______________________ / _______________________</p>
        <p class="muted small">Ведомость сформирована {{ now()->format('d.m.Y H:i') }}, {{ $inventory->user?->name }}.</p>
    </div>
</div>
@endsection
