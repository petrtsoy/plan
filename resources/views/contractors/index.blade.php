@extends('layouts.app')
@section('title', 'Сервисные организации')
@section('content')
<div class="head">
    <h1>Сервисные организации</h1>
    <a class="btn" href="{{ route('maintenance.index') }}">Что пора обслуживать</a>
</div>
<p class="muted small">
    Кто делает ТО, поверку и перезарядку. О договоре, который кончается в ближайшие
    {{ $warnDays }} дней, предупреждает сводка и главная страница.
</p>

<div class="table-wrap"><table>
    <tr><th>Организация</th><th>Контакты</th><th>Договор</th><th>В регламенте</th><th>Работ</th><th></th></tr>
    @forelse($contractors as $c)
        <tr>
            <td>
                {{ $c->name }}
                <div class="small muted">{{ $c->bin ? 'БИН '.$c->bin : '' }} {{ $c->license ? ' · '.$c->license : '' }}</div>
            </td>
            <td class="small">
                {{ $c->contact_person ?? '' }}
                <div class="muted">{{ collect([$c->phone, $c->email])->filter()->implode(' · ') ?: '–' }}</div>
            </td>
            <td class="nowrap small">
                {{ $c->contract_number ?? '–' }}
                <div>
                    @if(! $c->contract_until)
                        <span class="muted">срок не указан</span>
                    @elseif($c->contract_until->lt(today()))
                        <span class="badge bad">кончился {{ $c->contract_until->format('d.m.Y') }}</span>
                    @elseif($c->contract_until->lte(today()->addDays($warnDays)))
                        <span class="badge warn">до {{ $c->contract_until->format('d.m.Y') }}</span>
                    @else
                        <span class="badge ok">до {{ $c->contract_until->format('d.m.Y') }}</span>
                    @endif
                </div>
            </td>
            <td><a href="{{ route('maintenance.index', ['contractor_id' => $c->id]) }}">{{ $c->plans_count }}</a></td>
            <td><a href="{{ route('maintenance.records', ['contractor_id' => $c->id]) }}">{{ $c->records_count }}</a></td>
            <td>
                <details>
                    <summary class="btn link">Правка</summary>
                    <form method="post" action="{{ route('contractors.update', $c) }}">
                        @csrf @method('put')
                        @include('contractors.fields', ['c' => $c])
                        <div class="actions"><button class="btn primary">Сохранить</button></div>
                    </form>
                </details>
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Организаций пока нет.</td></tr>
    @endforelse
</table></div>

<h2>Добавить организацию</h2>
<form method="post" action="{{ route('contractors.store') }}" class="panel">
    @csrf
    @include('contractors.fields', ['c' => null])
    <div class="actions"><button class="btn primary">Добавить</button></div>
</form>
@endsection
