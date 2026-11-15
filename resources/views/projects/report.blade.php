@extends('layouts.app')
@section('title', 'Отчет по проекту – '.$project->name)
@section('content')
{{-- Печатается из браузера: @media print убирает меню, кнопки и рамки (public/css/app.css). --}}
<div class="no-print">
    <div class="crumbs"><a href="{{ route('projects.index') }}">Проекты</a> / <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></div>
    <div class="head">
        <h1>Отчет по проекту</h1>
        <button type="button" class="btn primary" onclick="window.print()">Печать</button>
    </div>
    <p class="muted small">Страница свернута под печать: лишнее не печатается, таблицы не рвутся по строкам.
    Чтобы получить PDF, в окне печати выберите "Сохранить как PDF".</p>
</div>

<article class="report">
    <header class="report-head">
        <h1>{{ $project->name }}</h1>
        <p class="report-sub">
            {{ $project->office?->display_name ?? 'филиал не задан' }} ·
            {{ $project->status->label() }}@if($project->chosenBuilding): {{ $project->chosenBuilding->name }}@endif ·
            отчет от {{ now()->format('d.m.Y') }}
        </p>
        @if($project->goal)<p>{{ $project->goal }}</p>@endif
    </header>

    <section>
        <h2>Что нужно разместить</h2>
        <table class="report-table">
            <thead><tr><th>Что</th><th>Категория</th><th>Сколько</th><th>Требования к помещению</th></tr></thead>
            <tbody>
                @forelse($project->needs as $need)
                    <tr>
                        <td>{{ $need->label }}</td>
                        <td>{{ $need->category->name }}</td>
                        <td>{{ $need->quantity }}</td>
                        <td>{{ implode(', ', \App\Services\Specs::summary($need->effectiveRequirements())) ?: '–' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Потребности не заданы.</td></tr>
                @endforelse
            </tbody>
            @if($project->needs->isNotEmpty())
                <tfoot><tr><th colspan="2">Всего единиц</th><th colspan="2">{{ $project->needs->sum('quantity') }}</th></tr></tfoot>
            @endif
        </table>
    </section>

    <section>
        <h2>Здания</h2>
        <table class="report-table">
            <thead><tr><th>Здание</th><th>Адрес</th><th>Площадь, м²</th><th>Цена</th><th>Поместилось</th></tr></thead>
            <tbody>
                @forelse($rows as $row)
                    @php($b = $row['building'])
                    <tr>
                        <td>
                            {{ $b->name }}
                            @unless($b->is_candidate)<br><small>выбрано – настоящее здание филиала</small>@endunless
                        </td>
                        <td>{{ $b->address ?? '–' }}</td>
                        <td>{{ $b->total_area_m2 ? (float) $b->total_area_m2 : '–' }}</td>
                        <td>{{ $b->price ? number_format((float) $b->price, 0, ',', ' ').' '.$b->price_note : '–' }}</td>
                        <td>{{ $row['placed'] }} из {{ $row['total'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Зданий-кандидатов нет.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @foreach($rows as $row)
        @php($b = $row['building'])
        <section class="report-building">
            <h2>{{ $b->name }}</h2>
            <p class="report-sub">
                {{ $b->address ?? 'адрес не указан' }} ·
                {{ $b->total_area_m2 ? (float) $b->total_area_m2.' м²' : 'площадь не указана' }} ·
                {{ $b->price ? number_format((float) $b->price, 0, ',', ' ').' '.$b->price_note : 'цена не указана' }}
            </p>

            <h3>Что поместилось</h3>
            <table class="report-table">
                <thead><tr><th>Потребность</th><th>Нужно</th><th>Расставлено</th><th>Не хватает</th></tr></thead>
                <tbody>
                    @forelse($row['needs'] as $need)
                        <tr>
                            <td>{{ $need['need'] }}</td>
                            <td>{{ $need['quantity'] }}</td>
                            <td>{{ $need['placed'] }}</td>
                            <td>{{ max(0, $need['quantity'] - $need['placed']) ?: '–' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Потребности не заданы.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <h3>Чего не хватает</h3>
            @php($problems = collect($variants[$b->id] ?? [])->flatMap(fn ($v) => $v['problems'])->merge($row['unfit'])->unique()->values())
            @if($problems->isNotEmpty())
                <ul>@foreach($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
            @else
                <p>Все, что расставлено, встает по месту и по требованиям к помещению.</p>
            @endif

            <h3>Варианты расстановки</h3>
            @php($list = collect($variants[$b->id] ?? []))
            @if($list->isNotEmpty())
                <table class="report-table">
                    <thead><tr><th>Вариант</th><th>Этаж</th><th>Позиций</th><th>Влезает</th></tr></thead>
                    <tbody>
                        @foreach($list as $variant)
                            <tr>
                                <td>{{ $variant['layout']->name }}</td>
                                <td>{{ $variant['layout']->floor->name }}</td>
                                <td>{{ count($variant['items']) }}</td>
                                <td>{{ $variant['fits'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p>Вариантов расстановки нет – в этом здании еще ничего не примеряли.</p>
            @endif

            @if($b->notes)<p><strong>Заметки:</strong> {{ $b->notes }}</p>@endif
        </section>
    @endforeach

    <section>
        <h2>Решение</h2>
        @if($project->status === \App\Enums\ProjectStatus::Chosen)
            <p><strong>Выбрано: {{ $project->chosenBuilding?->name }}.</strong></p>
        @elseif($project->status === \App\Enums\ProjectStatus::Rejected)
            <p><strong>Ни одно здание не выбрано.</strong></p>
        @else
            <p>Проект рассматривается, решения пока нет.</p>
        @endif
        @if($project->decision)<p>{{ $project->decision }}</p>@endif
        @if($project->decided_at)
            <p class="report-sub">{{ $project->decided_at->format('d.m.Y') }}@if($project->decidedBy), {{ $project->decidedBy->name }}@endif</p>
        @endif
        <div class="report-sign">
            <div>Подготовил ______________________</div>
            <div>Согласовано ______________________</div>
        </div>
    </section>
</article>
@endsection
