@extends('layouts.app')
@section('title', 'Акты передачи')
@section('content')
<h1>Акты передачи</h1>

@php
    // Направление – одной строкой: филиал и МОЛ откуда, то же куда (шапка акта).
    $side = fn ($office, $mol) => collect([$office?->display_name ?? 'вне филиалов', $mol?->full_name])->filter()->implode(', ');
    $state = function ($transfer) {
        $message = $transfer->outbox->firstWhere('target', 'onec');
        return match (true) {
            ! $message => ['mute', 'в 1С не уходит'],
            $message->status === 'sent' => ['ok', 'ушло в 1С'.($transfer->onec_number ? ' № '.$transfer->onec_number : '')],
            $message->status === 'failed' => ['bad', 'не ушло в 1С'],
            default => ['warn', 'в очереди в 1С'],
        };
    };
@endphp

<h2>Черновики</h2>
<p class="muted small">Собираются сами: все, что передали в одном направлении (филиал и МОЛ откуда – куда),
    ложится в один черновик. "Оформить" закрывает акт и ставит документ в очередь в 1С.</p>

@forelse($drafts as $draft)
    <div class="panel">
        <div class="settings-head">
            <h2>{{ $draft->number }} <span class="badge mute">черновик</span>
                @if($draft->origin === 'it')<span class="badge">из it.his.kz</span>@endif
            </h2>
            <a class="btn" href="{{ route('transfers.print', $draft) }}" target="_blank">Печать</a>
        </div>
        <p class="small muted">Откуда: {{ $side($draft->fromOffice, $draft->fromMol) }}
            &rarr; куда: {{ $side($draft->toOffice, $draft->toMol) }}</p>
        <div class="table-wrap"><table>
            <tr><th>Наименование</th><th>Инвентарный</th><th>Серийный</th></tr>
            @foreach($draft->items as $item)
                <tr>
                    <td><a href="{{ route('assets.show', $item->asset_id) }}">{{ $item->title }}</a></td>
                    <td>{{ $item->inventory_number ?: '–' }}</td>
                    <td class="small">{{ $item->asset?->serial_number ?: '–' }}</td>
                </tr>
            @endforeach
        </table></div>
        <form method="post" action="{{ route('transfers.issue', $draft) }}" class="actions">@csrf
            <input type="text" name="reason" maxlength="191" placeholder="Основание" value="{{ $draft->reason }}">
            <button class="btn primary">Оформить</button>
        </form>
    </div>
@empty
    <p class="muted">Черновиков нет: они появляются, когда единицу передают в другой филиал или другому МОЛ.</p>
@endforelse

<h2>Оформленные</h2>
<div class="table-wrap"><table>
    <tr><th>Номер</th><th>Дата</th><th>Откуда</th><th>Куда</th><th>Строк</th><th>Отправка</th><th></th></tr>
    @forelse($issued as $act)
        @php([$kind, $label] = $state($act))
        <tr>
            <td class="nowrap">{{ $act->number }}@if($act->origin === 'it')<div class="small muted">из it.his.kz</div>@endif</td>
            <td class="nowrap">{{ $act->transfer_date->format('d.m.Y') }}</td>
            <td>{{ $side($act->fromOffice, $act->fromMol) }}</td>
            <td>{{ $side($act->toOffice, $act->toMol) }}</td>
            <td>{{ $act->items_count }}</td>
            <td><span class="badge {{ $kind }}">{{ $label }}</span></td>
            <td><a class="btn" href="{{ route('transfers.print', $act) }}" target="_blank">Печать</a></td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Оформленных актов нет.</td></tr>
    @endforelse
</table></div>
@include('partials.pager', ['paginator' => $issued])
@endsection
