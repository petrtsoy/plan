{{-- Логотип из настроек или значок "П". --}}
@if(config('app.logo'))
    <img class="brand-mark logo {{ $class ?? '' }}" src="{{ route('brand.logo', ['v' => config('app.logo_version')]) }}" alt="{{ config('app.name') }}">
@else
    <span class="brand-mark {{ $class ?? '' }}">П</span>
@endif
