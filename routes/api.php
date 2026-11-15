<?php

use App\Http\Controllers\Api\ItController;
use Illuminate\Support\Facades\Route;

// События перемещений из it.his.kz: без сессии и CSRF, ключ в заголовке X-Api-Key
// (раздел "Настройки" => "it.his.kz"). Контракт – docs/it-exchange.md.
Route::post('/it/moves', [ItController::class, 'moves'])->middleware('throttle:120,1')->name('api.it.moves');
