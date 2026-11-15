@extends('layouts.app')
@section('title', $room->title.' – '.$inventory->name)
@section('content')
@php($badge = ['found' => 'ok', 'misplaced' => 'warn', 'missing' => 'bad', 'surplus' => '', 'pending' => 'mute'])
<div class="crumbs"><a href="{{ route('inventories.show', $inventory) }}">{{ $inventory->name }}</a> / {{ $room->floor->path }}</div>
<h1>{{ $room->title }}</h1>
<p class="muted small">
    Ожидается единиц: {{ $items->count() }}, не проверено: {{ $items->where('mark', \App\Enums\InventoryMark::Pending)->count() }}.
    @unless($canCheck) Отмечать может бухгалтер или инженер. @endunless
</p>

<div class="inv-list">
    @forelse($items as $item)
        <div class="inv-item" data-row data-mark="{{ $item->mark->value }}">
            <div class="inv-item-head">
                <div>
                    @if($item->asset)
                        <a href="{{ route('assets.show', $item->asset) }}"><strong>{{ $item->asset->title }}</strong></a>
                        <div class="muted small">
                            {{ $item->asset->inventory_number ? 'инв. '.$item->asset->inventory_number : $item->asset->category->name }}
                            @if($item->expectedMol) · {{ $item->expectedMol->full_name }} @endif
                        </div>
                    @else
                        <strong>Излишек</strong>
                        <div class="muted small">{{ $item->note }}</div>
                    @endif
                    @if($item->mark === \App\Enums\InventoryMark::Misplaced && $item->foundRoom)
                        <div class="muted small">нашли: {{ $item->foundRoom->title }}</div>
                    @endif
                    @if($item->expected_room_id && $item->expected_room_id !== $room->id)
                        <div class="muted small">числится: {{ $item->expectedRoom?->title }}</div>
                    @endif
                </div>
                <span data-state class="badge {{ $badge[$item->mark->value] }}">{{ $item->mark->label() }}</span>
            </div>
            @if($canCheck && $item->mark !== \App\Enums\InventoryMark::Surplus)
                @include('inventories.marks', ['url' => route('inventories.mark', [$inventory, $item])])
            @endif
        </div>
    @empty
        <p class="muted">В этом помещении по ведомости ничего не числится. Нашли что-то – отсканируйте этикетку или добавьте излишком в ведомости.</p>
    @endforelse
</div>

@if($canCheck)
    <form class="panel" data-surplus action="{{ route('inventories.check', $inventory) }}">
        <h2>Нашли лишнее</h2>
        <input type="hidden" name="found_room_id" value="{{ $room->id }}">
        <label>Что нашли</label>
        <input name="note" maxlength="191" placeholder="Шкаф без номера">
        <div class="actions"><button class="btn">Добавить в ведомость</button></div>
    </form>

    <template id="tpl-rooms">
        @foreach($rooms as $r)<option value="{{ $r->id }}" @selected($r->id === $room->id)>{{ $r->title }}</option>@endforeach
    </template>
@endif
@endsection

@push('scripts')
<script src="{{ asset('js/inventory.js') }}?v={{ filemtime(public_path('js/inventory.js')) }}"></script>
@endpush
