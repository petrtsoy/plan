@extends('layouts.app')
@section('title', 'Главная')
@section('content')
<h1>{{ config('app.name') }}</h1>
<p class="muted small">{{ config('app.tagline') }}</p>

<div class="table-wrap"><table>
    <tr><th>Показатель</th><th>Значение</th></tr>
    <tr><td>Зданий</td><td>{{ $buildings }}</td></tr>
    @foreach(\App\Enums\AssetStatus::cases() as $s)
        <tr><td>{{ $s->label() }}</td><td>{{ $byStatus[$s->value] ?? 0 }}</td></tr>
    @endforeach
    <tr><td>Не расставлено на планах</td><td>{{ $unplaced }}</td></tr>
    <tr><td>ТО и поверка просрочены</td><td>{{ $overdue }}</td></tr>
    <tr><td>ТО и поверка в ближайшие {{ config('services.alerts.maintenance_days') }} дней</td><td>{{ $dueSoon }}</td></tr>
    <tr><td>Не ушло в 1С или it.his.kz</td><td>{{ $outboxFailed }}</td></tr>
    <tr><td>Синхронизация с HR</td><td>{{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('d.m.Y H:i') : 'не было' }}</td></tr>
</table></div>
@endsection
