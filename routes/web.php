<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ContractorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FloorController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\OnecController;
use App\Http\Controllers\OutboxController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransferController;
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
    // Сверка с 1С: только чтение и показ расхождений, править ничего не дает.
    Route::get('/onec', [OnecController::class, 'index'])->name('onec.index');
    // Файлы к записям (акт ТО, свидетельство о поверке): только вошедшим.
    Route::get('/files/{attachment}', [FileController::class, 'show'])->name('files.show');

    Route::middleware('role:plans')->group(function () {
        Route::post('/buildings', [BuildingController::class, 'store'])->name('buildings.store');
        Route::put('/buildings/{building}', [BuildingController::class, 'update'])->name('buildings.update');
        Route::post('/buildings/{building}/floors', [BuildingController::class, 'storeFloor'])->name('buildings.floors.store');
        Route::put('/floors/{floor}', [FloorController::class, 'update'])->name('floors.update');
        Route::post('/floors/{floor}/plans', [FloorController::class, 'uploadPlan'])->name('floors.plans.store');
        Route::post('/plans/{plan}/calibrate', [FloorController::class, 'calibrate'])->name('plans.calibrate');
        Route::post('/plans/{plan}/crop', [FloorController::class, 'crop'])->name('plans.crop');
        Route::get('/plans/{plan}/clean', [FloorController::class, 'cleanTypes'])->name('plans.clean');
        Route::get('/plans/{plan}/clean/{index}.png', [FloorController::class, 'cleanPreview'])->whereNumber('index')->name('plans.clean.preview');
        Route::get('/plans/{plan}/clean/result.png', [FloorController::class, 'cleanResult'])->name('plans.clean.result');
        Route::post('/plans/{plan}/clean', [FloorController::class, 'clean']);
        Route::post('/floors/{floor}/rooms', [RoomController::class, 'store'])->name('floors.rooms.store');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::post('/floors/{floor}/import', [FloorController::class, 'import'])->name('floors.import');
    });

    Route::middleware('role:assets')->group(function () {
        Route::post('/floors/{floor}/assets', [AssetController::class, 'store'])->name('floors.assets.store');
        Route::post('/assets/{asset}/place', [AssetController::class, 'place'])->name('assets.place');
        Route::put('/onec/offices', [OnecController::class, 'updateOffice'])->name('onec.offices.update');

        // Акты передачи: кто правит оборудование, тот и оформляет (в том числе черновики из it.his.kz).
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/{transfer}/print', [TransferController::class, 'print'])->name('transfers.print');
        Route::post('/transfers/{transfer}/issue', [TransferController::class, 'issue'])->name('transfers.issue');

        // ТО и поверка: что пора обслуживать, регламент и выполнение, сервисные организации.
        Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
        Route::get('/maintenance/records', [MaintenanceController::class, 'records'])->name('maintenance.records');
        Route::get('/maintenance/assets/{asset}', [MaintenanceController::class, 'asset'])->name('maintenance.asset');
        Route::post('/maintenance/assets/{asset}/plans', [MaintenanceController::class, 'storePlan'])->name('maintenance.plans.store');
        Route::post('/maintenance/assets/{asset}/plans/from-model', [MaintenanceController::class, 'planFromModel'])->name('maintenance.plans.from-model');
        Route::put('/maintenance/plans/{plan}', [MaintenanceController::class, 'updatePlan'])->name('maintenance.plans.update');
        Route::post('/maintenance/assets/{asset}/records', [MaintenanceController::class, 'storeRecord'])->name('maintenance.records.store');
        Route::get('/contractors', [ContractorController::class, 'index'])->name('contractors.index');
        Route::post('/contractors', [ContractorController::class, 'store'])->name('contractors.store');
        Route::put('/contractors/{contractor}', [ContractorController::class, 'update'])->name('contractors.update');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/users', [AdminController::class, 'users'])->name('admin.users');
        Route::post('/admin/users', [AdminController::class, 'storeUser'])->name('admin.users.store');
        Route::put('/admin/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
        Route::get('/admin/outbox', [OutboxController::class, 'index'])->name('admin.outbox');
        Route::post('/admin/outbox/{message}/retry', [OutboxController::class, 'retry'])->name('admin.outbox.retry');
        Route::get('/admin/settings', [SettingsController::class, 'index'])->name('admin.settings');
        Route::post('/admin/settings/import', [SettingsController::class, 'import'])->name('admin.settings.import');
        Route::put('/admin/settings/{group}', [SettingsController::class, 'update'])->name('admin.settings.update');
    });

    Route::get('/audit', [AdminController::class, 'audit'])->name('audit');
});
