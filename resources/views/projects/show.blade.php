@extends('layouts.app')
@section('title', $project->name)
@section('content')
@php($open = $project->isOpen())
<div class="crumbs"><a href="{{ route('projects.index') }}">Проекты</a></div>
<div class="head">
    <h1>{{ $project->name }}</h1>
    <a class="btn" href="{{ route('projects.report', $project) }}">Отчет для руководства</a>
</div>
<p class="muted small">
    {{ $project->office?->display_name ?? 'филиал не задан' }} ·
    @if($project->status === \App\Enums\ProjectStatus::Chosen)
        <span class="badge ok">Выбрано: {{ $project->chosenBuilding?->name }}</span>
    @elseif($project->status === \App\Enums\ProjectStatus::Rejected)
        <span class="badge mute">{{ $project->status->label() }}</span>
    @else
        <span class="badge">{{ $project->status->label() }}</span>
    @endif
    @if($project->decided_at)
        · решение {{ $project->decided_at->format('d.m.Y') }}@if($project->decidedBy), {{ $project->decidedBy->name }}@endif
    @endif
</p>
@if($project->goal)<p>{{ $project->goal }}</p>@endif
@if($project->decision)<div class="panel review"><p><strong>Решение:</strong> {{ $project->decision }}</p></div>@endif

{{-- Сравнение: по каждому зданию – сколько потребностей поместилось и что не подошло. --}}
<h2>Сравнение зданий</h2>
<div class="table-wrap"><table>
    <tr><th>Здание</th><th>Цена</th><th>Площадь, м²</th><th>Поместилось</th><th>Этажей с планом</th><th>Что не подошло</th></tr>
    @forelse($rows as $row)
        @php($b = $row['building'])
        <tr>
            <td>
                @if($b->is_candidate)<strong>{{ $b->name }}</strong>@else<a href="{{ route('buildings.show', $b) }}">{{ $b->name }}</a>@endif
                @if($b->address)<div class="small muted">{{ $b->address }}</div>@endif
                @unless($b->is_candidate)<div class="small"><span class="badge ok">настоящее здание</span></div>@endunless
            </td>
            <td class="nowrap">
                @if($b->price){{ number_format((float) $b->price, 0, ',', ' ') }}@else<span class="muted">–</span>@endif
                @if($b->price_note)<div class="small muted">{{ $b->price_note }}</div>@endif
            </td>
            <td>{{ $b->total_area_m2 ? (float) $b->total_area_m2 : '–' }}</td>
            <td>
                @if($row['total'])
                    @if($row['placed'] >= $row['total'])<span class="badge ok">{{ $row['placed'] }} из {{ $row['total'] }}</span>
                    @else<span class="badge warn">{{ $row['placed'] }} из {{ $row['total'] }}</span>@endif
                    <div class="small muted">
                        @foreach($row['needs'] as $need)
                            <div>{{ $need['need'] }}: {{ $need['placed'] }} из {{ $need['quantity'] }}</div>
                        @endforeach
                    </div>
                @else
                    <span class="muted">потребностей нет</span>
                @endif
            </td>
            <td>{{ $b->floors->filter(fn ($f) => $f->plan)->count() }} из {{ $b->floors->count() }}</td>
            <td class="small">
                @if($row['unfit'])
                    <ul class="li-problems">@foreach($row['unfit'] as $u)<li>{{ $u }}</li>@endforeach</ul>
                @else
                    <span class="muted">по требованиям все подходит</span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Зданий-кандидатов пока нет.</td></tr>
    @endforelse
</table></div>

{{-- Кандидаты: этажи, подложки и варианты расстановки на них. --}}
<h2>Здания-кандидаты</h2>
<p class="muted small">Кандидат – не наше здание: в списках зданий филиала его нет и настоящие единицы
туда не переместить, зато этажи, подложки и помещения у него свои – на них и примеряем.</p>
@forelse($project->buildings as $b)
    <div class="panel">
        <div class="head" style="margin-bottom:12px">
            <h2>{{ $b->name }} @unless($b->is_candidate)<span class="badge ok">выбрано</span>@endunless</h2>
            @if($b->price)<span class="muted small">{{ number_format((float) $b->price, 0, ',', ' ') }} {{ $b->price_note }}</span>@endif
        </div>
        <div class="table-wrap"><table>
            <tr><th>Этаж</th><th>Чертеж</th><th>Помещений</th><th>Варианты расстановки</th></tr>
            @forelse($b->floors as $f)
                <tr>
                    <td><a href="{{ route('floors.show', $f) }}">{{ $f->name }}</a></td>
                    <td>
                        @if(!$f->plan)<span class="badge mute">нет</span>
                        @elseif($f->plan->isCalibrated())<span class="badge ok">масштаб задан</span>
                        @else<span class="badge warn">без масштаба</span>@endif
                    </td>
                    <td>{{ $f->rooms->count() }}</td>
                    <td>
                        @foreach(collect($variants[$b->id] ?? [])->filter(fn ($v) => $v['layout']->floor_id === $f->id) as $variant)
                            <div>
                                <a href="{{ route('layouts.show', $variant['layout']) }}">{{ $variant['layout']->name }}</a>
                                <span class="small muted">{{ $variant['fits'] }} из {{ count($variant['items']) }} влезает</span>
                            </div>
                        @endforeach
                        <a class="small" href="{{ route('floors.layouts.index', $f) }}">все варианты этажа &rarr;</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Этажей нет.</td></tr>
            @endforelse
        </table></div>

        @if($canEdit && $open)
            <div class="grid">
                <form method="post" action="{{ route('projects.candidates.floors.store', $b) }}">
                    @csrf
                    <h2>Добавить этаж</h2>
                    <div class="fields">
                        <div><label>Номер</label><input name="level" type="number" value="{{ ($b->floors->max('level') ?? 0) + 1 }}" required></div>
                        <div><label>Название</label><input name="name" placeholder="2 этаж" required></div>
                    </div>
                    <div class="actions"><button class="btn">Добавить</button></div>
                </form>
                @if($b->is_candidate)
                    <details>
                        <summary><strong>Изменить кандидата</strong></summary>
                        <form method="post" action="{{ route('projects.candidates.update', $b) }}" style="margin-top:12px">
                            @csrf @method('put')
                            <div class="fields">
                                <div><label>Название</label><input name="name" value="{{ $b->name }}" required></div>
                                <div><label>Адрес</label><input name="address" value="{{ $b->address }}"></div>
                                <div><label>Площадь, м²</label><input name="total_area_m2" type="number" step="0.01" value="{{ $b->total_area_m2 }}"></div>
                                <div><label>Цена</label><input name="price" type="number" step="0.01" value="{{ $b->price }}"></div>
                                <div><label>Цена – что это</label><input name="price_note" value="{{ $b->price_note }}" placeholder="аренда в месяц"></div>
                                <div class="field-full"><label>Заметки</label><textarea name="notes" rows="2">{{ $b->notes }}</textarea></div>
                            </div>
                            <div class="actions"><button class="btn primary">Сохранить</button></div>
                        </form>
                    </details>
                @endif
            </div>
        @endif
    </div>
@empty
    <p class="muted">Кандидатов пока нет.</p>
@endforelse

@if($canEdit && $open)
    <details class="panel">
        <summary><strong>Добавить здание-кандидат</strong></summary>
        <form method="post" action="{{ route('projects.candidates.store', $project) }}" enctype="multipart/form-data" style="margin-top:12px">
            @csrf
            <div class="fields">
                <div><label>Название</label><input name="name" placeholder='БЦ "Нурлы"' required></div>
                <div><label>Адрес</label><input name="address"></div>
                <div><label>Общая площадь, м²</label><input name="total_area_m2" type="number" step="0.01"></div>
                <div><label>Цена</label><input name="price" type="number" step="0.01"></div>
                <div><label>Цена – что это</label><input name="price_note" placeholder="аренда в месяц, покупка"></div>
                <div><label>Первый этаж: номер</label><input name="floor_level" type="number" value="1"></div>
                <div><label>Первый этаж: название</label><input name="floor_name" placeholder="1 этаж"></div>
                <div class="field-full">
                    <label>Чертеж этажа: PDF, PNG, JPG, WebP до 64 МБ</label>
                    <input type="file" name="file" accept=".pdf,.png,.jpg,.jpeg,.webp">
                    <div class="small muted">Загрузим сразу – дальше задать масштаб и обвести помещения, как на любом этаже.</div>
                </div>
                <div class="field-full"><label>Заметки</label><textarea name="notes" rows="2"></textarea></div>
            </div>
            <div class="actions"><button class="btn primary">Добавить кандидата</button></div>
        </form>
    </details>
@endif

{{-- Что нужно разместить. --}}
<h2>Что нужно разместить</h2>
<div class="table-wrap"><table>
    <tr><th>Что</th><th>Сколько</th><th>Модель или габариты</th><th>Требования к помещению</th><th>Расставлено в вариантах</th>@if($canEdit && $open)<th></th>@endif</tr>
    @forelse($project->needs as $need)
        <tr>
            <td>
                <strong>{{ $need->label }}</strong>
                <div class="small muted">{{ $need->category->name }}</div>
                @if($need->notes)<div class="small muted">{{ $need->notes }}</div>@endif
            </td>
            <td>{{ $need->quantity }}</td>
            <td class="small">
                @if($need->model)
                    {{ $need->model->title }}
                    @if($need->model->width_mm)<div class="muted">{{ $need->model->width_mm }} × {{ $need->model->depth_mm }} мм</div>@endif
                @elseif($need->asset)
                    <a href="{{ route('assets.show', $need->asset) }}">{{ $need->asset->title }}</a>
                    <div class="muted">переезд</div>
                @elseif($need->width_mm)
                    {{ $need->width_mm }} × {{ $need->depth_mm }} мм
                @elseif($need->area_m2)
                    помещение {{ (float) $need->area_m2 }} м²
                @else
                    <span class="muted">не задано</span>
                @endif
            </td>
            <td class="small">
                @php($specs = \App\Services\Specs::summary($need->effectiveRequirements()))
                @if($specs)<div class="tag-list">@foreach($specs as $s)<span class="tag">{{ $s }}</span>@endforeach</div>
                @else<span class="muted">–</span>@endif
            </td>
            <td>{{ $need->items_count }}</td>
            @if($canEdit && $open)
                <td>
                    <form method="post" action="{{ route('projects.needs.destroy', $need) }}" onsubmit="return confirm('Убрать потребность? Позиции вариантов, которые на нее ссылаются, тоже уйдут.')">
                        @csrf @method('delete')
                        <button class="btn link">убрать</button>
                    </form>
                </td>
            @endif
        </tr>
    @empty
        <tr><td colspan="{{ $canEdit && $open ? 6 : 5 }}" class="muted">Пусто. Добавьте, что нужно разместить – по этому и сравниваются здания.</td></tr>
    @endforelse
</table></div>

@if($canEdit && $open)
    <details class="panel">
        <summary><strong>Добавить потребность</strong></summary>
        <form method="post" action="{{ route('projects.needs.store', $project) }}" style="margin-top:12px">
            @csrf
            <div class="fields">
                <div>
                    <label>Категория</label>
                    <select name="category_id" required>
                        @foreach($categories->groupBy(fn ($c) => $c->layer->name) as $layer => $cats)
                            <optgroup label="{{ $layer }}">
                                @foreach($cats as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div><label>Что это</label><input name="label" placeholder="Гематологический анализатор" required></div>
                <div><label>Сколько</label><input name="quantity" type="number" min="1" value="1" required></div>
                <div>
                    <label>Модель, если известна</label>
                    <select name="model_id">
                        <option value="">не выбрана</option>
                        @foreach($models as $m)<option value="{{ $m->id }}">{{ $m->title }}</option>@endforeach
                    </select>
                    <div class="small muted">Выбрана – габариты и требования берутся из нее.</div>
                </div>
                @if($movable->isNotEmpty())
                    <div>
                        <label>Повезем существующую единицу</label>
                        <select name="asset_id">
                            <option value="">нет, новое</option>
                            @foreach($movable as $a)<option value="{{ $a->id }}">{{ $a->title }}{{ $a->inventory_number ? ' · '.$a->inventory_number : '' }}</option>@endforeach
                        </select>
                    </div>
                @endif
                <div><label>Ширина, мм</label><input name="width_mm" type="number" min="1"></div>
                <div><label>Глубина, мм</label><input name="depth_mm" type="number" min="1"></div>
                <div>
                    <label>Или нужна площадь, м²</label>
                    <input name="area_m2" type="number" step="0.01" min="0">
                    <div class="small muted">Для кабинета или склада целиком, когда габаритов нет.</div>
                </div>
                <div class="field-full"><label>Заметки</label><textarea name="notes" rows="2"></textarea></div>
            </div>
            <h2>Требования к помещению</h2>
            <p class="small muted">Если модель не выбрана. С моделью – берутся ее требования, а заданное здесь важнее.</p>
            @include('partials.specs-fields', ['name' => 'requirements', 'values' => []])
            <div class="actions"><button class="btn primary">Добавить</button></div>
        </form>
    </details>
@endif

{{-- Итог проекта. --}}
@if($canEdit && $open && $project->buildings->isNotEmpty())
    <h2>Итог</h2>
    <div class="grid">
        <form method="post" action="{{ route('projects.choose', $project) }}" class="panel"
              onsubmit="return confirm('Выбрать это здание? Оно станет настоящим зданием филиала и появится в списке зданий.')">
            @csrf
            <h2>Выбрать здание</h2>
            <div class="fields">
                <div>
                    <label>Здание</label>
                    <select name="building_id" required>
                        @foreach($project->buildings->where('is_candidate', true) as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Филиал, к которому отнести</label>
                    <select name="office_id">
                        <option value="">как у проекта</option>
                        @foreach($offices as $o)<option value="{{ $o->id }}" @selected($project->office_id === $o->id)>{{ $o->display_name }}</option>@endforeach
                    </select>
                    <div class="small muted">У настоящего здания филиал обязателен.</div>
                </div>
                <div class="field-full"><label>Почему</label><textarea name="decision" rows="2" placeholder="Дешевле и есть слив в канализацию"></textarea></div>
            </div>
            <div class="actions"><button class="btn primary">Выбрать</button></div>
        </form>

        <form method="post" action="{{ route('projects.reject', $project) }}" class="panel"
              onsubmit="return confirm('Отметить, что ни одно здание не выбрано? Проект закроется, кандидаты останутся для истории.')">
            @csrf
            <h2>Не выбирать ничего</h2>
            <p class="small muted">Проект закроется, кандидаты и варианты останутся – видно, что рассматривали и почему отказались.</p>
            <label>Почему</label><textarea name="decision" rows="2" class="block" placeholder="Ни одно не подошло по площади"></textarea>
            <div class="actions"><button class="btn">Не выбирать</button></div>
        </form>
    </div>
@endif

@if($canEdit)
    <details class="panel">
        <summary><strong>Изменить проект</strong></summary>
        <form method="post" action="{{ route('projects.update', $project) }}" style="margin-top:12px">
            @csrf @method('put')
            <div class="fields">
                <div><label>Название</label><input name="name" value="{{ $project->name }}" required></div>
                <div>
                    <label>Филиал</label>
                    <select name="office_id">
                        <option value="">не задан</option>
                        @foreach($offices as $o)<option value="{{ $o->id }}" @selected($project->office_id === $o->id)>{{ $o->display_name }}</option>@endforeach
                    </select>
                </div>
                <div class="field-full"><label>Зачем</label><textarea name="goal" rows="2">{{ $project->goal }}</textarea></div>
            </div>
            <div class="actions"><button class="btn primary">Сохранить</button></div>
        </form>
    </details>
@endif
@endsection
