@extends('layouts.app')
@section('title', 'Журнал')
@section('content')
<h1>Журнал изменений</h1>

@php
    $types = ['asset' => 'Оборудование', 'transfer' => 'Акты передачи', 'maintenance' => 'ТО и поверка', 'maintenance_plan' => 'Регламент ТО', 'building' => 'Здания', 'floor' => 'Этажи', 'plan' => 'Подложки', 'room' => 'Помещения', 'zone' => 'Зоны', 'layer' => 'Слои', 'category' => 'Категории', 'asset_model' => 'Модели', 'contractor' => 'Сервисные организации', 'layout' => 'Варианты расстановки', 'inventory' => 'Инвентаризации', 'attachment' => 'Файлы', 'user' => 'Пользователи', 'auth' => 'Входы', 'hr_sync' => 'Синхронизация HR', 'setting' => 'Настройки'];
    $actions = ['created' => 'создано', 'updated' => 'изменено', 'deleted' => 'удалено', 'restored' => 'восстановлено', 'login' => 'вход', 'logout' => 'выход', 'login_denied' => 'отказ во входе', 'synced' => 'синхронизация'];
@endphp

<form class="filters" method="get">
    <select name="type">
        <option value="">Все</option>
        @foreach($types as $v => $l)<option value="{{ $v }}" @selected(request('type') === $v)>{{ $l }}</option>@endforeach
    </select>
    <button class="btn">Показать</button>
    @if(request()->hasAny(['type','id']))<a class="btn link" href="{{ route('audit') }}">Сбросить</a>@endif
</form>

<div class="table-wrap"><table>
    <tr><th>Когда</th><th>Кто</th><th>Что</th><th>Изменения</th></tr>
    @forelse($logs as $log)
        <tr>
            <td class="small">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
            <td>{{ $log->user?->name ?? 'система' }}<div class="small muted">{{ $log->ip }}</div></td>
            <td>
                {{ $types[$log->subject_type] ?? $log->subject_type }}
                @if($log->subject_id && $log->subject_type !== 'setting')#{{ $log->subject_id }}@endif
                – {{ $actions[$log->action] ?? $log->action }}
                @if($log->comment)<div class="small muted">{{ $log->comment }}</div>@endif
            </td>
            <td class="small">
                @foreach($log->changes ?? [] as $field => $value)
                    <div><span class="muted">{{ $field }}:</span>
                        @if($log->action === 'updated' && is_array($value) && array_is_list($value) && count($value) === 2)
                            {{ is_array($value[0]) ? json_encode($value[0], JSON_UNESCAPED_UNICODE) : ($value[0] ?? '–') }} &rarr; {{ is_array($value[1]) ? json_encode($value[1], JSON_UNESCAPED_UNICODE) : ($value[1] ?? '–') }}
                        @else
                            {{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}
                        @endif
                    </div>
                @endforeach
            </td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">Записей нет.</td></tr>
    @endforelse
</table></div>
@include('partials.pager', ['paginator' => $logs])
@endsection
