@extends('layouts.app')
@section('title', 'Настройки')
@section('content')
<h1>Настройки</h1>
<p class="muted small">Значения хранятся в базе и действуют поверх .env. Секреты зашифрованы ключом
приложения и здесь не показываются: пустое поле – оставить как есть.</p>

@php $fromEnv = collect($groups)->flatten(1)->where('from_db', false)->count(); @endphp
@if($fromEnv)
<form method="post" action="{{ route('admin.settings.import') }}" class="panel settings-import">
    @csrf
    <span>Из .env берется настроек: {{ $fromEnv }} (помечены "env"). Перенести их текущие значения в БД? Уже заданные здесь не изменятся.</span>
    <button class="btn primary">Перенести в БД</button>
</form>
@endif

@foreach(\App\Services\Settings::GROUPS as $group => $title)
<form method="post" action="{{ route('admin.settings.update', $group) }}" enctype="multipart/form-data" class="panel settings" id="{{ $group }}">
    @csrf @method('put')
    <div class="settings-head">
        <h2>{{ $title }}</h2>
        @foreach(collect($groups[$group])->where('type', 'bool') as $f)
            <label class="check"><input type="hidden" name="{{ $f['field'] }}" value="0"><input type="checkbox" name="{{ $f['field'] }}" value="1" @checked(old($f['field'], $f['value']))> {{ $f['label'] }}</label>
        @endforeach
    </div>

    @foreach(collect($groups[$group])->where('type', '!=', 'bool') as $f)
        <div class="setting-row">
            <div class="setting-label">
                <label for="s-{{ $f['field'] }}">{{ $f['label'] }} @unless($f['from_db'])<span class="env" title="Значение из .env, в БД записи нет">env</span>@endunless</label>
                @if($f['hint'])<div class="small muted">{{ $f['hint'] }}</div>@endif
            </div>
            <div class="setting-control">
                @switch($f['type'])
                    @case('secret')
                        <input id="s-{{ $f['field'] }}" type="password" name="{{ $f['field'] }}" autocomplete="new-password" placeholder="{{ $f['is_set'] ? 'задан' : 'не задан' }}">
                        @if($f['extra']['generate'] ?? false)
                            <label class="check"><input type="checkbox" name="{{ $f['field'] }}_generate" value="1"> сгенерировать</label>
                        @endif
                        @if($f['is_set'])
                            <label class="check"><input type="checkbox" name="{{ $f['field'] }}_clear" value="1"> очистить</label>
                        @endif
                        @break
                    @case('image')
                        @if($f['is_set'])<img src="{{ route('brand.logo', ['v' => config('app.logo_version')]) }}" alt="" class="setting-logo">@endif
                        <input id="s-{{ $f['field'] }}" type="file" name="{{ $f['field'] }}" accept=".png,.jpg,.jpeg,.svg,.webp">
                        @if($f['is_set'])
                            <label class="check"><input type="checkbox" name="{{ $f['field'] }}_clear" value="1"> убрать</label>
                        @endif
                        @break
                    @case('select')
                        <select id="s-{{ $f['field'] }}" name="{{ $f['field'] }}">
                            @foreach($f['extra']['options'] as $v => $l)<option value="{{ $v }}" @selected((string) old($f['field'], $f['value']) === (string) $v)>{{ $l }}</option>@endforeach
                        </select>
                        @break
                    @case('int')
                        <input id="s-{{ $f['field'] }}" type="number" class="short" name="{{ $f['field'] }}" value="{{ old($f['field'], $f['value']) }}" min="{{ $f['extra']['min'] ?? 0 }}" max="{{ $f['extra']['max'] ?? '' }}" required>
                        @break
                    @default
                        <input id="s-{{ $f['field'] }}" type="{{ ['url' => 'url', 'email' => 'email', 'date' => 'date'][$f['type']] ?? 'text' }}" name="{{ $f['field'] }}" value="{{ old($f['field'], $f['value']) }}">
                @endswitch
            </div>
        </div>
    @endforeach

    <div class="settings-foot"><button class="btn primary">Сохранить</button></div>
</form>
@endforeach
@endsection
