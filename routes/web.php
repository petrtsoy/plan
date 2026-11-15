<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FloorController;
use App\Http\Controllers\RoomController;
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

    // Смотреть планы могут все; здания, этажи, подложки и помещения правит раздел "Планы",
    // расстановку и карточки – "Оборудование".
    Route::get('/buildings', [BuildingController::class, 'index'])->name('buildings.index');
    Route::get('/buildings/{building}', [BuildingController::class, 'show'])->name('buildings.show');
    Route::get('/floors/{floor}', [FloorController::class, 'show'])->name('floors.show');
    Route::get('/plans/{plan}/image', [FloorController::class, 'planImage'])->name('plans.image');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');

    Route::middleware('role:plans')->group(function () {
        Route::post('/buildings', [BuildingController::class, 'store'])->name('buildings.store');
        Route::put('/buildings/{building}', [BuildingController::class, 'update'])->name('buildings.update');
        Route::post('/buildings/{building}/floors', [BuildingController::class, 'storeFloor'])->name('buildings.floors.store');
        Route::put('/floors/{floor}', [FloorController::class, 'update'])->name('floors.update');
        Route::post('/floors/{floor}/plans', [FloorController::class, 'uploadPlan'])->name('floors.plans.store');
        Route::post('/plans/{plan}/calibrate', [FloorController::class, 'calibrate'])->name('plans.calibrate');
        Route::post('/floors/{floor}/rooms', [RoomController::class, 'store'])->name('floors.rooms.store');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
    });

    Route::middleware('role:assets')->group(function () {
        Route::post('/floors/{floor}/assets', [AssetController::class, 'store'])->name('floors.assets.store');
        Route::post('/assets/{asset}/place', [AssetController::class, 'place'])->name('assets.place');
    });

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
