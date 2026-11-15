@extends('layouts.app')
@section('title', 'Здания и планы')
@section('content')
@php($canPlans = auth()->user()->edits(\App\Enums\Area::Plans))
<h1>Здания и планы</h1>
<p class="muted small">Здания филиалов, этажи и чертежи. Филиалы приходят из HR; здания-кандидаты проектов здесь не показываются.</p>

@forelse($offices->filter(fn ($o) => $o->buildings->isNotEmpty() || $canPlans) as $office)
    <h2>{{ $office->display_name }}</h2>
    @if($office->buildings->isEmpty())
        <p class="muted small">Зданий пока нет.</p>
    @else
        <div class="table-wrap"><table>
            <tr><th>Здание</th><th>Адрес</th><th>Этажей</th><th>Площадь, м²</th></tr>
            @foreach($office->buildings as $b)
                <tr>
                    <td><a href="{{ route('buildings.show', $b) }}">{{ $b->name }}</a></td>
                    <td>{{ $b->address ?? '–' }}</td>
                    <td>{{ $b->floors_count }}</td>
                    <td>{{ $b->total_area_m2 ?? '–' }}</td>
                </tr>
            @endforeach
        </table></div>
    @endif
@empty
    <p class="muted">Филиалов пока нет – они появятся после синхронизации с HR.</p>
@endforelse

@if($canPlans && $offices->isNotEmpty())
    <h2>Добавить здание</h2>
    <form method="post" action="{{ route('buildings.store') }}" class="panel">
        @csrf
        @include('buildings.fields', ['b' => null, 'offices' => $offices])
        <div class="actions"><button class="btn primary">Добавить</button></div>
    </form>
@endif
@endsection
