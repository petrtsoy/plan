{{-- Карточка единицы (в том числе после скана QR с этикетки): если в филиале идет
     инвентаризация, отметку можно поставить прямо отсюда. Ждет $check – контекст из
     App\Services\Inventories::cardContext(). --}}
@if($check)
    @php
        $inventory = $check['inventory'];
        $item = $check['item'];
        $canCheck = auth()->user()->edits(\App\Enums\Area::Inventory);
        $badge = ['found' => 'ok', 'misplaced' => 'warn', 'missing' => 'bad', 'surplus' => '', 'pending' => 'mute'];
    @endphp
    <div class="panel inv-check">
        <h2>Идет инвентаризация</h2>
        <p class="muted small">
            <a href="{{ route('inventories.show', $inventory) }}">{{ $inventory->name }}</a>{{ $inventory->order_number ? ', приказ '.$inventory->order_number : '' }}.
            @if($item)
                Отметка: <span class="badge {{ $badge[$item->mark->value] }}">{{ $item->mark->label() }}</span>
                @if($item->expectedRoom) числится: {{ $item->expectedRoom->title }}.@endif
                @if($item->foundRoom && $item->mark === \App\Enums\InventoryMark::Misplaced) Нашли: {{ $item->foundRoom->title }}.@endif
            @else
                В ведомости этой единицы нет – отметка добавит ее излишком.
            @endif
        </p>
        @if($canCheck)
            @include('inventories.marks', [
                'url' => $item ? route('inventories.mark', [$inventory, $item]) : route('inventories.check', $inventory),
                'markAsset' => $item ? null : $asset->id,
                'markReload' => true,
            ])
            <template id="tpl-rooms">
                @foreach($check['rooms'] as $room)<option value="{{ $room->id }}" @selected($room->id === $asset->room_id)>{{ $room->title }}</option>@endforeach
            </template>
        @else
            <p class="muted small">Отмечать может бухгалтер или инженер.</p>
        @endif
    </div>

    @push('scripts')
        <script src="{{ asset('js/inventory.js') }}?v={{ filemtime(public_path('js/inventory.js')) }}"></script>
    @endpush
@endif
