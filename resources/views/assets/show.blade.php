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

@if($canAssets)
    <details class="panel" @if($errors->any()) open @endif>
        <summary><strong>Правка карточки</strong> <span class="muted small">учет, медицинское изделие, покупка</span></summary>
        <form method="post" action="{{ route('assets.update', $asset) }}">
            @csrf @method('put')
            <div class="fields">
                <div>
                    <label>Категория</label>
                    <select name="category_id" required>
                        @foreach($categories->groupBy(fn ($c) => $c->layer->name) as $layer => $cats)
                            <optgroup label="{{ $layer }}">
                                @foreach($cats as $c)<option value="{{ $c->id }}" @selected(old('category_id', $asset->category_id) == $c->id)>{{ $c->name }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Модель</label>
                    <select name="model_id">
                        <option value="">– без модели –</option>
                        @foreach($models as $m)<option value="{{ $m->id }}" @selected(old('model_id', $asset->model_id) == $m->id)>{{ $m->title }}</option>@endforeach
                    </select>
                </div>
                <div><label>Название</label><input name="name" value="{{ old('name', $asset->name) }}" placeholder="по модели или категории, если пусто"></div>
                <div>
                    <label>Статус</label>
                    <select name="status" required>
                        @foreach(\App\Enums\AssetStatus::options() as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $asset->status->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label>Инвентарный №</label><input name="inventory_number" value="{{ old('inventory_number', $asset->inventory_number) }}"></div>
                <div><label>Серийный №</label><input name="serial_number" value="{{ old('serial_number', $asset->serial_number) }}"></div>
                <div><label>Отделение</label><input name="department" value="{{ old('department', $asset->department) }}"></div>
                <div><label>Рег. удостоверение</label><input name="registration_number" value="{{ old('registration_number', $asset->registration_number) }}"></div>
                <div><label>РУ действует до</label><input name="registration_until" type="date" value="{{ old('registration_until', $asset->registration_until?->format('Y-m-d')) }}"></div>
                <div><label>Номер в реестре СИ</label><input name="gsi_number" value="{{ old('gsi_number', $asset->gsi_number) }}"></div>
                <div><label>Год выпуска</label><input name="manufactured_year" type="number" min="1900" max="{{ date('Y') + 1 }}" value="{{ old('manufactured_year', $asset->manufactured_year) }}"></div>
                <div><label>Введено в эксплуатацию</label><input name="commissioned_at" type="date" value="{{ old('commissioned_at', $asset->commissioned_at?->format('Y-m-d')) }}"></div>
                <div><label>Куплено</label><input name="purchased_at" type="date" value="{{ old('purchased_at', $asset->purchased_at?->format('Y-m-d')) }}"></div>
                <div><label>Стоимость</label><input name="price" type="number" step="0.01" min="0" value="{{ old('price', $asset->price) }}"></div>
                <div><label>Поставщик</label><input name="supplier" value="{{ old('supplier', $asset->supplier) }}"></div>
                <div><label>Гарантия до</label><input name="warranty_until" type="date" value="{{ old('warranty_until', $asset->warranty_until?->format('Y-m-d')) }}"></div>
                <div>
                    <label>Сервисная организация</label>
                    <select name="contractor_id">
                        <option value="">– не выбрана –</option>
                        @foreach($contractors as $c)<option value="{{ $c->id }}" @selected(old('contractor_id', $asset->contractor_id) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select>
                </div>
                <div><label>Списано</label><input name="written_off_at" type="date" value="{{ old('written_off_at', $asset->written_off_at?->format('Y-m-d')) }}"></div>
                <div class="field-full"><label>Заметки</label><textarea name="notes" rows="2">{{ old('notes', $asset->notes) }}</textarea></div>
            </div>
            <p class="small muted">Место (филиал, этаж, помещение, точка) меняется на плане – там же пишется история перемещений.</p>
            <div class="actions"><button class="btn primary">Сохранить</button></div>
        </form>
    </details>

    <details class="panel">
        <summary><strong>Закрепление</strong> <span class="muted small">МОЛ и пользователь – с записью в истории</span></summary>
        <form method="post" action="{{ route('assets.assign', $asset) }}">
            @csrf
            <div class="fields">
                <div>
                    <label>МОЛ</label>
                    <select name="mol_id">
                        <option value="">– нет –</option>
                        @foreach($employees as $e)<option value="{{ $e->id }}" @selected($asset->mol_id == $e->id)>{{ $e->full_name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label>Пользователь</label>
                    <select name="employee_id">
                        <option value="">– нет –</option>
                        @foreach($employees as $e)<option value="{{ $e->id }}" @selected($asset->employee_id == $e->id)>{{ $e->full_name }}</option>@endforeach
                    </select>
                </div>
                <div><label>Комментарий</label><input name="comment" placeholder="основание передачи"></div>
            </div>
            <p class="small muted">Смена МОЛ – передача: строка ляжет в черновик акта, в 1С он уйдет при оформлении.</p>
            <div class="actions"><button class="btn primary">Закрепить</button></div>
        </form>
    </details>
@endif

<h2>Файлы</h2>
<div class="table-wrap"><table>
    <tr><th>Файл</th><th>Что это</th><th>Размер</th><th>Загрузил</th><th></th></tr>
    @forelse($asset->attachments as $file)
        <tr>
            <td>
                <a href="{{ route('files.show', $file) }}" target="_blank" rel="noopener">{{ $file->name }}</a>
                @if($file->isImage())<span class="muted small">картинка</span>@endif
            </td>
            <td>{{ \App\Models\Attachment::KINDS[$file->kind] ?? $file->kind }}</td>
            <td class="nowrap">{{ $file->size ? round($file->size / 1024) . ' КБ' : '–' }}</td>
            <td class="nowrap">{{ $file->user?->name ?? '–' }}, {{ $file->created_at->format('d.m.Y') }}</td>
            <td>
                @if($canAssets)
                    <form method="post" action="{{ route('files.destroy', $file) }}" onsubmit="return confirm('Убрать файл из карточки? Сам файл на диске останется.')">
                        @csrf @method('delete')
                        <button class="btn link danger">Убрать</button>
                    </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">Файлов нет.</td></tr>
    @endforelse
</table></div>

@if($canAssets)
    <form method="post" action="{{ route('assets.files.store', $asset) }}" enctype="multipart/form-data" class="panel">
        @csrf
        <h2>Добавить файл</h2>
        <div class="fields">
            <div><label>Файл до 32 МБ</label><input type="file" name="file" required></div>
            <div>
                <label>Что это</label>
                <select name="kind">
                    @foreach(\App\Models\Attachment::KINDS as $value => $label)
                        <option value="{{ $value }}" @selected($value === 'photo')>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div><label>Название</label><input name="name" placeholder="как у файла, если пусто"></div>
        </div>
        <div class="actions"><button class="btn primary">Загрузить</button></div>
    </form>
@endif

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
