@extends('layouts.app')
@section('title', $asset->title)
@section('content')
<div class="crumbs">
    @if($asset->floor)<a href="{{ route('floors.show', $asset->floor) }}">{{ $asset->floor->path }}</a>@else{{ $asset->office?->display_name ?? 'Без места' }}@endif
</div>
<h1>{{ $asset->title }}</h1>
<p class="muted small">{{ $asset->category->layer->name }} / {{ $asset->category->name }}</p>

@include('inventories.asset-mark', ['check' => $check ?? null])

<div class="grid">
    <div class="panel">
        <h2>Учет</h2>
        <dl class="props">
            <dt>Статус</dt><dd>{{ $asset->status->label() }}</dd>
            <dt>Инвентарный №</dt><dd>{{ $asset->inventory_number ?? '–' }}</dd>
            <dt>Серийный №</dt><dd>{{ $asset->serial_number ?? '–' }}</dd>
            @if($asset->model)<dt>Модель</dt><dd>{{ $asset->model->title }}</dd>@endif
            <dt>МОЛ</dt><dd>{{ $asset->mol?->full_name ?? '–' }}</dd>
            <dt>Пользователь</dt><dd>{{ $asset->employee?->full_name ?? '–' }}</dd>
            @if($asset->price)<dt>Стоимость</dt><dd>{{ number_format((float) $asset->price, 2, ',', ' ') }}</dd>@endif
            @if($asset->warranty_until)<dt>Гарантия до</dt><dd>{{ $asset->warranty_until->format('d.m.Y') }}</dd>@endif
        </dl>
    </div>
    <div class="panel">
        <h2>Место</h2>
        <dl class="props">
            <dt>Филиал</dt><dd>{{ $asset->office?->display_name ?? '–' }}</dd>
            <dt>Этаж</dt><dd>@if($asset->floor)<a href="{{ route('floors.show', $asset->floor) }}">{{ $asset->floor->path }}</a>@else не расставлено @endif</dd>
            <dt>Помещение</dt><dd>{{ $asset->room?->title ?? '–' }}</dd>
            <dt>Отделение</dt><dd>{{ $asset->department ?? '–' }}</dd>
        </dl>
    </div>
    @if($asset->category->is_medical || $asset->registration_number)
        <div class="panel">
            <h2>Медицинское изделие</h2>
            <dl class="props">
                <dt>Рег. удостоверение</dt><dd>{{ $asset->registration_number ?? '–' }}</dd>
                <dt>Действует до</dt><dd>{{ $asset->registration_until?->format('d.m.Y') ?? '–' }}</dd>
                @if($asset->category->is_measuring)<dt>Номер в реестре СИ</dt><dd>{{ $asset->gsi_number ?? '–' }}</dd>@endif
                <dt>Введено в эксплуатацию</dt><dd>{{ $asset->commissioned_at?->format('d.m.Y') ?? '–' }}</dd>
            </dl>
        </div>
    @endif
</div>

<h2>ТО и поверка</h2>
<div class="table-wrap"><table>
    <tr><th>Что</th><th>Раз в, мес.</th><th>Было</th><th>Срок</th></tr>
    @forelse($asset->maintenancePlans as $p)
        @php($due = $p->next_due_at)
        <tr @if(!$p->is_active) class="muted" @endif>
            <td>{{ $p->kind->label() }}</td>
            <td>{{ $p->interval_months }}</td>
            <td>{{ $p->last_done_at?->format('d.m.Y') ?? '–' }}</td>
            <td>
                @if(!$due)–
                @elseif($due->lt(today()))<span class="badge bad">{{ $due->format('d.m.Y') }}</span>
                @elseif($due->lte(today()->addDays((int) config('services.alerts.maintenance_days'))))<span class="badge warn">{{ $due->format('d.m.Y') }}</span>
                @else<span class="badge ok">{{ $due->format('d.m.Y') }}</span>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">Регламента нет.</td></tr>
    @endforelse
</table></div>

<h2>История перемещений</h2>
<div class="table-wrap"><table>
    <tr><th>Когда</th><th>Куда</th><th>Кто</th><th>Комментарий</th></tr>
    @forelse($asset->moves as $m)
        <tr>
            <td class="nowrap">{{ $m->moved_at->format('d.m.Y H:i') }}</td>
            <td>{{ $m->toOffice?->display_name }}{{ $m->toRoom ? ' / '.$m->toRoom->title : '' }}</td>
            <td>{{ $m->user?->name ?? ($m->origin === 'it' ? 'it.his.kz' : '–') }}</td>
            <td>{{ $m->comment }}</td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">Перемещений не было.</td></tr>
    @endforelse
</table></div>
@endsection
