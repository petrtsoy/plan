<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login/dev', [LoginController::class, 'dev'])->name('login.dev');
    Route::get('/auth/sso/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('/auth/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
});

// Обратный выход от auth.his.kz: без сессии и CSRF, с подписью HMAC.
Route::post('/auth/sso/logout', [SsoController::class, 'backChannelLogout'])->name('sso.logout');

// Логотип из настроек: нужен и на странице входа.
Route::get('/brand/logo', [SettingsController::class, 'logo'])->name('brand.logo');

Route::middleware(['auth', 'role'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/users', [AdminController::class, 'users'])->name('admin.users');
        Route::post('/admin/users', [AdminController::class, 'storeUser'])->name('admin.users.store');
        Route::put('/admin/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
        Route::get('/admin/settings', [SettingsController::class, 'index'])->name('admin.settings');
        Route::post('/admin/settings/import', [SettingsController::class, 'import'])->name('admin.settings.import');
        Route::put('/admin/settings/{group}', [SettingsController::class, 'update'])->name('admin.settings.update');
    });

    Route::get('/audit', [AdminController::class, 'audit'])->name('audit');
});
