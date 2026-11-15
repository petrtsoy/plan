@extends('layouts.app')
@section('title', 'ТО – '.$asset->title)
@section('content')
@php
    use App\Services\Maintenance;
    $badges = ['overdue' => 'bad', 'soon' => 'warn', 'ok' => 'ok', 'none' => 'mute'];
    $resultBadges = ['ok' => 'ok', 'limited' => 'warn', 'failed' => 'bad'];
@endphp

<div class="crumbs">
    <a href="{{ route('maintenance.index') }}">Что пора обслуживать</a>
    @if($asset->floor) / <a href="{{ route('floors.show', $asset->floor) }}">{{ $asset->floor->path }}</a>@endif
</div>
<div class="head">
    <h1>{{ $asset->title }}</h1>
    <a class="btn" href="{{ route('assets.show', $asset) }}">Карточка единицы</a>
</div>
<p class="muted small">
    {{ $asset->category->layer->name }} / {{ $asset->category->name }}
    · {{ $asset->inventory_number ?? 'без инв. №' }}
    · {{ collect([$asset->office?->display_name, $asset->room?->title])->filter()->implode(' / ') ?: 'место не указано' }}
</p>

<h2>Регламент</h2>
<div class="table-wrap"><table>
    <tr><th>Что</th><th>Раз в, мес.</th><th>Было</th><th>Срок</th><th>Организация</th><th></th></tr>
    @forelse($asset->maintenancePlans as $plan)
        <tr @class(['muted' => ! $plan->is_active])>
            <td>{{ $plan->kind->label() }}</td>
            <td>{{ $plan->interval_months }}</td>
            <td class="nowrap">{{ $plan->last_done_at?->format('d.m.Y') ?? '–' }}</td>
            <td class="nowrap">
                @php($state = $plan->is_active ? Maintenance::state($plan->next_due_at, $days) : 'none')
                @if($plan->next_due_at)
                    <span class="badge {{ $badges[$state] }}">{{ $plan->next_due_at->format('d.m.Y') }}</span>
                @else
                    –
                @endif
                @if(! $plan->is_active)<span class="badge mute">не действует</span>@endif
            </td>
            <td class="small">{{ $plan->contractor?->name ?? '–' }}</td>
            <td>
                <details>
                    <summary class="btn link">Правка</summary>
                    <form method="post" action="{{ route('maintenance.plans.update', $plan) }}">
                        @csrf @method('put')
                        <input type="hidden" name="kind" value="{{ $plan->kind->value }}">
                        <div class="fields" style="margin-top:12px">
                            <div><label>Раз в, мес.</label><input type="number" name="interval_months" min="1" max="240" value="{{ $plan->interval_months }}" required></div>
                            <div><label>Срок</label><input type="date" name="next_due_at" value="{{ $plan->next_due_at?->toDateString() }}"></div>
                            <div><label>Организация</label>
                                <select name="contractor_id">
                                    <option value="">–</option>
                                    @foreach($contractors as $c)<option value="{{ $c->id }}" @selected($plan->contractor_id === $c->id)>{{ $c->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="field-full"><label>Примечание</label><textarea name="notes">{{ $plan->notes }}</textarea></div>
                            <div class="field-full"><label><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> Действует</label></div>
                        </div>
                        <div class="actions"><button class="btn primary">Сохранить</button></div>
                    </form>
                </details>
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Регламента нет: единица на плане серая, в сводку не попадает.</td></tr>
    @endforelse
</table></div>

<div class="grid">
    <form method="post" action="{{ route('maintenance.plans.store', $asset) }}" class="panel">
        @csrf
        <h2>Добавить в регламент</h2>
        <div class="fields">
            <div><label>Что делаем</label>
                <select name="kind" required>
                    @foreach($kinds as $v => $label)<option value="{{ $v }}" @selected(old('kind') === $v)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><label>Раз в, мес.</label><input type="number" name="interval_months" min="1" max="240" value="{{ old('interval_months', 12) }}" required></div>
            <div><label>Срок (если известен)</label><input type="date" name="next_due_at" value="{{ old('next_due_at') }}"></div>
            <div><label>Организация</label>
                <select name="contractor_id">
                    <option value="">–</option>
                    @foreach($contractors as $c)<option value="{{ $c->id }}" @selected((int) old('contractor_id') === $c->id)>{{ $c->name }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="btn primary">Добавить</button>
            @if($asset->model?->intervals)
                <button class="btn" form="plan-from-model">По регламенту модели</button>
            @endif
        </div>
    </form>

    <form method="post" action="{{ route('maintenance.records.store', $asset) }}" class="panel" enctype="multipart/form-data">
        @csrf
        <h2>Записать выполнение</h2>
        <div class="fields">
            <div><label>Что сделано</label>
                <select name="kind" required>
                    @foreach($kinds as $v => $label)<option value="{{ $v }}" @selected(old('kind', $asset->maintenancePlans->first()?->kind->value) === $v)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><label>Когда</label><input type="date" name="performed_at" value="{{ old('performed_at', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required></div>
            <div><label>Результат</label>
                <select name="result" required>
                    @foreach($results as $v => $label)<option value="{{ $v }}" @selected(old('result', 'ok') === $v)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><label>Действует до</label><input type="date" name="valid_until" value="{{ old('valid_until') }}"></div>
            <div><label>Организация</label>
                <select name="contractor_id">
                    <option value="">–</option>
                    @foreach($contractors as $c)<option value="{{ $c->id }}" @selected((int) old('contractor_id') === $c->id)>{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div><label>№ акта или свидетельства</label><input type="text" name="document_number" value="{{ old('document_number') }}" maxlength="64"></div>
            <div><label>Стоимость</label><input type="number" name="cost" step="0.01" min="0" value="{{ old('cost') }}"></div>
            <div><label>Акт или свидетельство (файл)</label><input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"></div>
            <div class="field-full"><label>Примечание</label><textarea name="notes">{{ old('notes') }}</textarea></div>
        </div>
        <p class="muted small">
            Без явного срока он считается от даты выполнения по интервалу регламента.
            "Не годно" срок не продлевает: единица остается красной до следующей успешной записи.
        </p>
        <div class="actions"><button class="btn primary">Записать</button></div>
    </form>
</div>

@if($asset->model?->intervals)
    <form method="post" action="{{ route('maintenance.plans.from-model', $asset) }}" id="plan-from-model" hidden>@csrf</form>
@endif

<h2>Что уже сделано</h2>
<div class="table-wrap"><table>
    <tr><th>Когда</th><th>Что</th><th>Результат</th><th>Действует до</th><th>Организация</th><th>Документ</th><th>Стоимость</th><th>Кто записал</th></tr>
    @forelse($asset->maintenanceRecords as $record)
        <tr>
            <td class="nowrap">{{ $record->performed_at->format('d.m.Y') }}</td>
            <td>{{ $record->kind->label() }}</td>
            <td><span class="badge {{ $resultBadges[$record->result->value] }}">{{ $record->result->label() }}</span></td>
            <td class="nowrap">{{ $record->valid_until?->format('d.m.Y') ?? '–' }}</td>
            <td class="small">{{ $record->contractor?->name ?? '–' }}</td>
            <td class="small">
                {{ $record->document_number ?? '–' }}
                @foreach($record->attachments as $file)
                    <div><a href="{{ route('files.show', $file) }}">{{ $file->name }}</a></div>
                @endforeach
            </td>
            <td class="nowrap">{{ $record->cost ? number_format((float) $record->cost, 2, ',', ' ') : '–' }}</td>
            <td class="small">{{ $record->user?->name ?? 'система' }}
                @if($record->notes)<div class="muted">{{ $record->notes }}</div>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">Записей нет.</td></tr>
    @endforelse
</table></div>
@endsection
