@extends('layouts.app')
@section('title', 'Что не ушло')
@section('content')
<h1>Что не ушло в 1С и it.his.kz</h1>
<p class="muted small">Перемещения и акты ставятся в очередь (outbox) в одной транзакции с изменением,
    отправляет их <code>outbox:send</code> раз в пять минут с растущей паузой после неудачи.
    После {{ \App\Models\OutboxMessage::MAX_ATTEMPTS }} неудач сообщение ждет здесь.
    Всего в очереди: {{ $waiting }}, отправлено: {{ $sent }}.</p>

<div class="table-wrap"><table>
    <tr><th>#</th><th>Куда</th><th>Что</th><th>Состояние</th><th>Попыток</th><th>Причина</th><th></th></tr>
    @forelse($messages as $message)
        <tr>
            <td class="nowrap">{{ $message->id }}<div class="small muted">{{ $message->created_at->format('d.m.Y H:i') }}</div></td>
            <td class="nowrap">{{ \App\Models\OutboxMessage::TARGETS[$message->target] ?? $message->target }}</td>
            <td>
                {{ $message->action }}
                @if($message->subject instanceof \App\Models\AssetTransfer)
                    <div class="small"><a href="{{ route('transfers.print', $message->subject) }}" target="_blank">акт {{ $message->subject->number }}</a></div>
                @elseif($message->subject)
                    <div class="small muted">{{ class_basename($message->subject) }} #{{ $message->subject->getKey() }}</div>
                @endif
            </td>
            <td>
                @if($message->status === 'failed')
                    <span class="badge bad">не ушло</span>
                @elseif($message->attempts > 0)
                    <span class="badge warn">повтор позже</span>
                @else
                    <span class="badge mute">ждет</span>
                @endif
                @if($message->next_attempt_at)<div class="small muted">снова {{ $message->next_attempt_at->format('d.m.Y H:i') }}</div>@endif
            </td>
            <td>{{ $message->attempts }} из {{ \App\Models\OutboxMessage::MAX_ATTEMPTS }}</td>
            <td class="small">{{ $message->error ?: '–' }}</td>
            <td>
                <form method="post" action="{{ route('admin.outbox.retry', $message) }}">@csrf
                    <button class="btn">Повторить</button>
                </form>
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Все ушло.</td></tr>
    @endforelse
</table></div>
@include('partials.pager', ['paginator' => $messages])
@endsection
