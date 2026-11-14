@extends('layouts.app')
@section('title', 'Вход')
@section('content')
<div class="login-card">
    <div class="login-brand">
        @include('partials.brand-mark', ['class' => 'lg'])
        <div>
            <h1>{{ config('app.name') }}</h1>
            <div class="muted small">{{ config('app.tagline') }}</div>
        </div>
    </div>

    @if($ssoEnabled)
        <a class="btn primary block" href="{{ route('sso.redirect') }}">Войти через auth.his.kz</a>
    @else
        <p class="muted small">Вход через auth.his.kz не настроен (Настройки – Вход через auth.his.kz).</p>
    @endif

    @if($devUsers->isNotEmpty())
        <div class="divider"><span>или для разработки</span></div>
        <form method="post" action="{{ route('login.dev') }}">
            @csrf
            <label for="dev-user">Войти как пользователь (dev)</label>
            <select id="dev-user" name="user_id" class="block">
                @foreach($devUsers as $u)<option value="{{ $u->id }}">{{ $u->name }} – {{ $u->role->label() }}</option>@endforeach
            </select>
            <button class="btn dark block" style="margin-top:16px">Войти</button>
        </form>
        <p class="muted small" style="margin-top:12px">Боевой вход – через auth.his.kz. Dev-вход доступен только вне production.</p>
    @endif
</div>
@endsection
