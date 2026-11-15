@extends('layouts.app')
@section('title', 'Главная')
@section('content')
@php($canAssets = auth()->user()->edits(\App\Enums\Area::Assets))
<h1>{{ config('app.name') }}</h1>
<p class="muted small">{{ config('app.tagline') }}</p>

<div class="table-wrap"><table>
    <tr><th>Показатель</th><th>Значение</th></tr>
    <tr><td>Зданий</td><td>{{ $buildings }}</td></tr>
    <tr><td>Проектов рассматривается</td><td>{{ $projects }}</td></tr>
    @foreach(\App\Enums\AssetStatus::cases() as $s)
        <tr><td>{{ $s->label() }}</td><td>{{ $byStatus[$s->value] ?? 0 }}</td></tr>
    @endforeach
    <tr><td>Не расставлено на планах</td><td>{{ $unplaced }}</td></tr>
    {{-- Сигналы по срокам: те же состояния, что на плане этажа и в утренней сводке. --}}
    <tr>
        <td>ТО и поверка просрочены</td>
        <td>@if($canAssets && $overdue)<a href="{{ route('maintenance.index', ['state' => 'overdue']) }}"><span class="badge bad">{{ $overdue }}</span></a>@else{{ $overdue }}@endif</td>
    </tr>
    <tr>
        <td>Срок ТО и поверки в ближайшие {{ config('services.alerts.maintenance_days') }} дней</td>
        <td>@if($canAssets && $dueSoon)<a href="{{ route('maintenance.index', ['state' => 'soon']) }}"><span class="badge warn">{{ $dueSoon }}</span></a>@else{{ $dueSoon }}@endif</td>
    </tr>
    <tr>
        <td>Без регламента ТО</td>
        <td>@if($canAssets && $noPlan)<a href="{{ route('maintenance.index', ['state' => 'none']) }}">{{ $noPlan }}</a>@else{{ $noPlan }}@endif</td>
    </tr>
    <tr><td>Гарантия кончается в ближайшие {{ config('services.alerts.warranty_days') }} дней</td><td>{{ $warrantySoon }}</td></tr>
    <tr>
        <td>Договоры сервисных организаций истекают</td>
        <td>@if($canAssets && $contracts)<a href="{{ route('contractors.index') }}">{{ $contracts }}</a>@else{{ $contracts }}@endif</td>
    </tr>
    <tr><td>Не ушло в 1С или it.his.kz</td><td>{{ $outboxFailed }}</td></tr>
    <tr><td>Синхронизация с HR</td><td>{{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('d.m.Y H:i') : 'не было' }}</td></tr>
</table></div>
@endsection
