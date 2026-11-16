<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetModelController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ContractorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FloorController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\LayoutController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\OnecController;
use App\Http\Controllers\OutboxController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\ZoneController;
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

    // Скан QR с этикетки: та же карточка, но без id в ссылке.
    Route::get('/a/{asset:qr_code}', [AssetController::class, 'show'])->name('assets.qr');

    // Инвентаризация: смотреть ведомость могут все, отмечать – бухгалтер и инженер.
    Route::get('/inventories', [InventoryController::class, 'index'])->name('inventories.index');
    Route::get('/inventories/{inventory}', [InventoryController::class, 'show'])->name('inventories.show');
    Route::get('/inventories/{inventory}/rooms/{room}', [InventoryController::class, 'room'])->name('inventories.room');
    Route::get('/inventories/{inventory}/report', [InventoryController::class, 'report'])->name('inventories.report');
    Route::get('/labels', [LabelController::class, 'index'])->name('labels');

    // Примерка и проекты: смотреть могут все, править – "Планы", утверждать – "Оборудование".
    Route::get('/models', [AssetModelController::class, 'index'])->name('models.index');
    Route::get('/floors/{floor}/layouts', [LayoutController::class, 'index'])->name('floors.layouts.index');
    Route::get('/layouts/{layout}', [LayoutController::class, 'show'])->name('layouts.show');
    Route::get('/layouts/{layout}/check', [LayoutController::class, 'check'])->name('layouts.check');
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/report', [ProjectController::class, 'report'])->name('projects.report');

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
        Route::post('/plans/{plan}/erase', [FloorController::class, 'erase'])->name('plans.erase');
        Route::post('/plans/{plan}/erase/at', [FloorController::class, 'eraseAt'])->name('plans.erase.at');
        Route::post('/plans/{plan}/restore', [FloorController::class, 'restorePlan'])->name('plans.restore');
        Route::post('/plans/{plan}/outline', [FloorController::class, 'outline'])->name('plans.outline');
        Route::post('/floors/{floor}/rooms', [RoomController::class, 'store'])->name('floors.rooms.store');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::post('/floors/{floor}/zones', [ZoneController::class, 'store'])->name('floors.zones.store');
        Route::put('/zones/{zone}', [ZoneController::class, 'update'])->name('zones.update');
        Route::post('/floors/{floor}/import', [FloorController::class, 'import'])->name('floors.import');

        // Варианты расстановки и проекты – там же, где здания и помещения.
        Route::post('/floors/{floor}/layouts', [LayoutController::class, 'store'])->name('floors.layouts.store');
        Route::put('/layouts/{layout}', [LayoutController::class, 'update'])->name('layouts.update');
        Route::post('/layouts/{layout}/items', [LayoutController::class, 'storeItem'])->name('layouts.items.store');
        Route::put('/layout-items/{item}', [LayoutController::class, 'updateItem'])->name('layout-items.update');
        Route::delete('/layout-items/{item}', [LayoutController::class, 'destroyItem'])->name('layout-items.destroy');

        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::post('/projects/{project}/buildings', [ProjectController::class, 'storeCandidate'])->name('projects.candidates.store');
        Route::put('/candidates/{building}', [ProjectController::class, 'updateCandidate'])->name('projects.candidates.update');
        Route::post('/candidates/{building}/floors', [ProjectController::class, 'storeFloor'])->name('projects.candidates.floors.store');
        Route::post('/projects/{project}/needs', [ProjectController::class, 'storeNeed'])->name('projects.needs.store');
        Route::put('/needs/{need}', [ProjectController::class, 'updateNeed'])->name('projects.needs.update');
        Route::delete('/needs/{need}', [ProjectController::class, 'destroyNeed'])->name('projects.needs.destroy');
        Route::post('/projects/{project}/choose', [ProjectController::class, 'choose'])->name('projects.choose');
        Route::post('/projects/{project}/reject', [ProjectController::class, 'reject'])->name('projects.reject');
    });

    Route::middleware('role:assets')->group(function () {
        Route::post('/floors/{floor}/assets', [AssetController::class, 'store'])->name('floors.assets.store');
        Route::post('/assets/{asset}/place', [AssetController::class, 'place'])->name('assets.place');
        Route::post('/floors/{floor}/assets/place-many', [AssetController::class, 'placeMany'])->name('floors.assets.place-many');
        Route::put('/assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
        Route::post('/assets/{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
        Route::post('/assets/{asset}/files', [FileController::class, 'store'])->name('assets.files.store');
        // Мягко: запись помечается, файл в storage остается.
        Route::delete('/files/{attachment}', [FileController::class, 'destroy'])->name('files.destroy');

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

        // Справочник моделей и утверждение варианта (заводит и переставляет единицы).
        Route::get('/models/create', [AssetModelController::class, 'create'])->name('models.create');
        Route::post('/models', [AssetModelController::class, 'store'])->name('models.store');
        Route::get('/models/{model}/edit', [AssetModelController::class, 'edit'])->name('models.edit');
        Route::put('/models/{model}', [AssetModelController::class, 'update'])->name('models.update');
        Route::post('/layouts/{layout}/approve', [LayoutController::class, 'approve'])->name('layouts.approve');
    });

    Route::middleware('role:inventory')->group(function () {
        Route::post('/inventories', [InventoryController::class, 'store'])->name('inventories.store');
        Route::post('/inventories/{inventory}/items/{item}', [InventoryController::class, 'mark'])->name('inventories.mark');
        Route::post('/inventories/{inventory}/check', [InventoryController::class, 'check'])->name('inventories.check');
        Route::post('/inventories/{inventory}/close', [InventoryController::class, 'close'])->name('inventories.close');
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
