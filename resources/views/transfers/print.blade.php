{{-- Акт на лист: отдельная страница без меню, печатается из браузера (@media print в app.css). --}}
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Акт {{ $transfer->number }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="print-body">
@php
    $side = fn ($office, $mol) => collect([$office?->display_name ?? 'вне филиалов', $mol?->full_name])->filter()->implode(', ');
@endphp
<div class="print-bar">
    <button class="btn primary" onclick="window.print()">Печать</button>
    <a class="btn" href="{{ route('transfers.index') }}">К актам</a>
    @if($transfer->isDraft())<span class="badge mute">черновик – еще не оформлен</span>@endif
</div>

<article class="act">
    <h1>Акт приема-передачи имущества № {{ $transfer->number }}</h1>
    <p class="act-date">от {{ $transfer->transfer_date->format('d.m.Y') }}</p>

    <table class="act-head">
        <tr><th>Сдает</th><td>{{ $side($transfer->fromOffice, $transfer->fromMol) }}</td></tr>
        <tr><th>Принимает</th><td>{{ $side($transfer->toOffice, $transfer->toMol) }}</td></tr>
        <tr><th>Основание</th><td>{{ $transfer->reason ?: '–' }}</td></tr>
    </table>

    <table class="act-items">
        <tr><th>№</th><th>Наименование</th><th>Инвентарный номер</th><th>Серийный номер</th></tr>
        @foreach($transfer->items as $i => $item)
            <tr>
                <td class="act-no">{{ $i + 1 }}</td>
                <td>{{ $item->title }}</td>
                <td>{{ $item->inventory_number ?: '–' }}</td>
                <td>{{ $item->asset?->serial_number ?: '–' }}</td>
            </tr>
        @endforeach
    </table>
    <p class="act-total">Всего передано единиц: {{ $transfer->items->count() }}</p>

    <div class="act-signs">
        <div>
            <p class="act-role">Сдал</p>
            <p class="act-line">{{ $transfer->fromMol?->full_name }}</p>
            <p class="act-small">должность, подпись, дата</p>
        </div>
        <div>
            <p class="act-role">Принял</p>
            <p class="act-line">{{ $transfer->toMol?->full_name }}</p>
            <p class="act-small">должность, подпись, дата</p>
        </div>
    </div>

    <p class="act-small act-foot">{{ config('app.name') }}
        @if($transfer->issued_at), оформлен {{ $transfer->issued_at->format('d.m.Y H:i') }}@endif
        @if($transfer->user), {{ $transfer->user->name }}@endif
        @if($transfer->origin === 'it'), перемещение из it.his.kz @endif
    </p>
</article>
</body>
</html>
