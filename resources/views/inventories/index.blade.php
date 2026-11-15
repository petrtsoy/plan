@extends('layouts.app')
@section('title', 'Инвентаризация')
@section('content')
@php($canCheck = auth()->user()->edits(\App\Enums\Area::Inventory))
<div class="head">
    <h1>Инвентаризация</h1>
    <a class="btn" href="{{ route('labels') }}">QR-этикетки</a>
</div>
<p class="muted small">Ведомость снимается при открытии: в нее попадает все, что числится в филиале (или в выбранных помещениях) с местом и МОЛ на тот момент. Отметки ставит комиссия – с ведомости или с телефона, сканируя этикетки.</p>

<div class="table-wrap">
    <table>
        <tr><th>Инвентаризация</th><th>Филиал</th><th>Приказ</th><th>Строк</th><th>Не проверено</th><th>Расхождений</th><th>Состояние</th></tr>
        @forelse($inventories as $inventory)
            <tr>
                <td><a href="{{ route('inventories.show', $inventory) }}">{{ $inventory->name }}</a>
                    @if($inventory->scope)<div class="muted small">только выбранные помещения</div>@endif
                </td>
                <td>{{ $inventory->office->display_name }}</td>
                <td class="nowrap">{{ $inventory->order_number ?? '–' }}@if($inventory->order_date)<div class="muted small">{{ $inventory->order_date->format('d.m.Y') }}</div>@endif</td>
                <td>{{ $inventory->items_count }}</td>
                <td>{{ $inventory->pending_count }}</td>
                <td>@if($inventory->diff_count)<span class="badge warn">{{ $inventory->diff_count }}</span>@else–@endif</td>
                <td>
                    @if($inventory->status === \App\Enums\InventoryStatus::Open)<span class="badge">Идет</span>
                    @else<span class="badge mute">Завершена {{ $inventory->closed_at?->format('d.m.Y') }}</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Инвентаризаций еще не было.</td></tr>
        @endforelse
    </table>
    @include('partials.pager', ['paginator' => $inventories])
</div>

@if($canCheck)
    <h2>Открыть инвентаризацию</h2>
    <form method="post" action="{{ route('inventories.store') }}" class="panel">
        @csrf
        <div class="fields">
            <div>
                <label>Филиал</label>
                <select name="office_id" id="inv-office" required>
                    @foreach($offices as $office)<option value="{{ $office->id }}" @selected(old('office_id') == $office->id)>{{ $office->display_name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label>Название</label>
                <input name="name" value="{{ old('name') }}" placeholder="Инвентаризация {{ today()->format('d.m.Y') }}" maxlength="191">
            </div>
            <div>
                <label>Приказ №</label>
                <input name="order_number" value="{{ old('order_number') }}" maxlength="64">
            </div>
            <div>
                <label>Дата приказа</label>
                <input type="date" name="order_date" value="{{ old('order_date') }}">
            </div>
            <div class="field-full">
                <label>Помещения <span class="muted small">(не выбирать – весь филиал)</span></label>
                <select name="rooms[]" id="inv-rooms" multiple size="8"></select>
            </div>
            <div class="field-full">
                <label>Категории <span class="muted small">(не выбирать – все)</span></label>
                <select name="categories[]" multiple size="6">
                    @foreach($categories->groupBy(fn ($c) => $c->layer->name) as $layer => $cats)
                        <optgroup label="{{ $layer }}">@foreach($cats as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</optgroup>
                    @endforeach
                </select>
            </div>
            <div class="field-full">
                <label>Примечание</label>
                <textarea name="notes" maxlength="5000">{{ old('notes') }}</textarea>
            </div>
        </div>
        <div class="actions"><button class="btn primary">Снять ведомость</button></div>
    </form>

    <script>
    (function () {
        var rooms = @json($roomsByOffice), office = document.getElementById('inv-office'), list = document.getElementById('inv-rooms');
        function fill() {
            list.innerHTML = '';
            (rooms[office.value] || []).forEach(function (r) {
                var o = document.createElement('option');
                o.value = r.id;
                o.textContent = r.floor + ' / ' + r.title;
                list.appendChild(o);
            });
            list.disabled = !list.options.length;
        }
        office.onchange = fill;
        fill();
    })();
    </script>
@endif
@endsection
