@extends('layouts.app')
@section('title', 'QR-этикетки')
@section('content')
<div class="crumbs no-print"><a href="{{ route('inventories.index') }}">Инвентаризация</a></div>
<div class="head no-print">
    <h1>QR-этикетки</h1>
    <button type="button" class="btn primary" onclick="window.print()">Печать</button>
</div>
<p class="muted small no-print">
    Этикетка 70 x 37 мм, по 24 на лист A4 (Avery L7159 и подобные). QR ведет на карточку единицы
    (<code>/a/код</code>): во время инвентаризации с нее сразу ставится отметка. Инвентарный номер и код
    напечатаны и текстом – по ним единицу найдут и без сканера.
</p>

<form method="get" class="filters no-print">
    <select name="office">
        <option value="">Все филиалы</option>
        @foreach($offices as $office)<option value="{{ $office->id }}" @selected(($filters['office'] ?? null) == $office->id)>{{ $office->display_name }}</option>@endforeach
    </select>
    @if($rooms->isNotEmpty())
        <select name="room">
            <option value="">Любое помещение</option>
            @foreach($rooms as $room)<option value="{{ $room->id }}" @selected(($filters['room'] ?? null) == $room->id)>{{ $room->title }}</option>@endforeach
        </select>
    @endif
    <select name="category">
        <option value="">Любая категория</option>
        @foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category'] ?? null) == $category->id)>{{ $category->name }}</option>@endforeach
    </select>
    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Название или номер" maxlength="191">
    <label class="check"><input type="checkbox" name="numbered" value="1" @checked($filters['numbered'] ?? false)> только с инвентарным номером</label>
    @if($inventory)<input type="hidden" name="inventory" value="{{ $inventory->id }}">@endif
    <button class="btn">Показать</button>
</form>

@if($inventory)
    <p class="muted small no-print">Только единицы из ведомости "{{ $inventory->name }}" ({{ $inventory->office->display_name }}).</p>
@endif
<p class="muted small no-print">Этикеток: {{ $assets->total() }}@if($assets->hasPages()), печатаются {{ $assets->count() }} с этой страницы@endif.</p>

<div class="labels">
    @forelse($assets as $asset)
        <div class="label">
            <div class="label-qr" data-qr="{{ url('/a/'.$asset->qr_code) }}"></div>
            <div class="label-text">
                <div class="label-name">{{ $asset->title }}</div>
                @if($asset->inventory_number)<div class="label-num">{{ $asset->inventory_number }}</div>@endif
                <div class="label-small">{{ $asset->room?->title ?? $asset->office?->display_name }}</div>
                <div class="label-small">/a/{{ $asset->qr_code }}</div>
            </div>
        </div>
    @empty
        <p class="muted">Единиц не нашлось – проверьте фильтры.</p>
    @endforelse
</div>
<div class="no-print">@include('partials.pager', ['paginator' => $assets])</div>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.5.2/qrcode.min.js"></script>
<script>
// QR рисует браузер: composer-зависимости для этого не нужны (как Leaflet на плане).
// Не загрузилась библиотека – на этикетке остаются номер и код текстом.
(function () {
    if (typeof qrcode !== 'function') return;
    document.querySelectorAll('[data-qr]').forEach(function (box) {
        var qr = qrcode(0, 'M');
        qr.addData(box.dataset.qr);
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 0, scalable: true });
    });
})();
</script>
@endpush
