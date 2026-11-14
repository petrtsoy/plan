<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Планы') – {{ config('app.name') }}</title>
    @if(config('app.logo'))<link rel="icon" href="{{ route('brand.logo', ['v' => config('app.logo_version')]) }}">@endif
    <meta name="theme-color" content="#0d9488">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    {{-- Свернутую панель ставим до отрисовки, иначе она мигает широкой (состояние – как в supply, localStorage). --}}
    <script>try { if (localStorage.getItem('sb-collapsed') === '1') document.documentElement.classList.add('sb-collapsed'); } catch (e) {}</script>
</head>
<body>
@auth
@php
    $user = auth()->user();
    // Меню – как в supply: темная панель слева, активный пункт подсвечен фирменным цветом.
    $nav = array_filter([
        ['Главная', route('dashboard'), 'home', request()->routeIs('dashboard')],
        ['Журнал', route('audit'), 'journal', request()->routeIs('audit')],
        $user->isAdmin() ? ['Пользователи', route('admin.users'), 'shield', request()->routeIs('admin.users*')] : null,
        $user->isAdmin() ? ['Настройки', route('admin.settings'), 'cog', request()->routeIs('admin.settings*')] : null,
    ]);
    $initials = mb_strtoupper(collect(explode(' ', $user->name))->filter(fn ($s) => preg_match('/^\pL/u', $s))->take(2)->map(fn ($s) => mb_substr($s, 0, 1))->implode(''));
@endphp
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="sb-head">
            <a class="brand-link" href="{{ route('dashboard') }}">@include('partials.brand-mark')</a>
            <span class="sb-title sb-label">{{ config('app.name') }}</span>
            <button type="button" class="sb-close" data-sb-close aria-label="Закрыть меню"><x-icon name="close"/></button>
        </div>

        <nav class="sb-nav">
            @foreach($nav as [$label, $href, $icon, $on])
                <a href="{{ $href }}" @class(['on' => $on]) title="{{ $label }}"><x-icon :name="$icon"/><span class="sb-label">{{ $label }}</span></a>
            @endforeach
        </nav>

        <div class="sb-foot">
            <button type="button" class="sb-account" data-account title="{{ $user->name }}">
                <span class="avatar">{{ $initials }}</span>
                <span class="sb-label sb-who">
                    <span class="sb-name">{{ $user->name }}</span>
                    <span class="sb-role">{{ $user->role->label() }}</span>
                </span>
                <x-icon name="chevron-up" class="sb-label chev"/>
            </button>
            <div class="account-menu" data-account-menu hidden>
                <form method="post" action="{{ route('logout') }}">@csrf
                    <button class="danger"><x-icon name="logout"/> Выйти</button>
                </form>
            </div>
            <button type="button" class="sb-collapse" data-sb-collapse title="Свернуть"><x-icon name="collapse"/><span class="sb-label">Свернуть</span></button>
        </div>
    </aside>
    <div class="sb-backdrop" data-sb-close></div>

    <div class="content">
        <header class="topbar">
            <button type="button" class="sb-open" data-sb-open aria-label="Меню"><x-icon name="menu"/></button>
            <div class="spacer"></div>
        </header>
        <main>
            @if($errors->any())
                <ul class="errors">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            @endif
            @yield('content')
        </main>
    </div>
</div>
{{-- Сообщения – всплывающие внизу справа, как тосты supply. --}}
@foreach(['ok', 'error'] as $key)
    @if(session($key))<div class="toast {{ $key }}" data-toast>{{ session($key) }}</div>@endif
@endforeach
@else
<main class="guest">
    @if(session('error'))<div class="flash error">{{ session('error') }}</div>@endif
    @yield('content')
</main>
@endauth

<script>
(function () {
    var root = document.documentElement;
    document.querySelectorAll('[data-sb-open]').forEach(function (b) { b.onclick = function () { root.classList.add('sb-open'); }; });
    document.querySelectorAll('[data-sb-close]').forEach(function (b) { b.onclick = function () { root.classList.remove('sb-open'); }; });
    var c = document.querySelector('[data-sb-collapse]');
    if (c) c.onclick = function () {
        var on = root.classList.toggle('sb-collapsed');
        try { localStorage.setItem('sb-collapsed', on ? '1' : '0'); } catch (e) {}
    };
    var acc = document.querySelector('[data-account]'), menu = document.querySelector('[data-account-menu]');
    if (acc) {
        acc.onclick = function (e) { e.stopPropagation(); menu.hidden = !menu.hidden; acc.classList.toggle('open', !menu.hidden); };
        document.addEventListener('click', function (e) { if (!menu.contains(e.target)) { menu.hidden = true; acc.classList.remove('open'); } });
    }
    document.querySelectorAll('[data-toast]').forEach(function (t) {
        setTimeout(function () { t.classList.add('hide'); }, t.classList.contains('error') ? 8000 : 4000);
        t.onclick = function () { t.classList.add('hide'); };
    });
})();
</script>
</body>
</html>
