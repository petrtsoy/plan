<?php

namespace App\Providers;

use App\Services\Settings;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Настройки из БД поверх .env. В консоли – только перед командой: config:cache
        // поднимает приложение без команды, и значения из БД (с расшифрованными
        // секретами) не должны попасть в bootstrap/cache/config.php.
        if ($this->app->runningInConsole()) {
            Event::listen(CommandStarting::class, fn () => Settings::apply());
        } else {
            Settings::apply();
        }
    }
}
