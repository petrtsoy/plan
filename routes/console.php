<?php

use App\Enums\Role;
use App\Models\User;
use App\Services\AuthSync;
use App\Services\HrSync;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('hr:sync {--full : Уволенные за 10 лет (первая загрузка)}', function (HrSync $sync) {
    $stats = $sync->run((bool) $this->option('full'));
    foreach ($stats as $what => $s) {
        $this->info(sprintf('%s: всего %d, новых %d, изменено %d%s', $what, $s['total'], $s['created'], $s['updated'],
            isset($s['fired']) ? ', уволено '.$s['fired'] : ''));
    }
})->purpose('Синхронизировать филиалы и сотрудников из HR');

Artisan::command('plan:user {iin} {name} {--role=admin} {--email=}', function (string $iin, string $name) {
    $role = Role::from($this->option('role'));
    $user = User::updateOrCreate(['iin' => $iin], [
        'name' => $name,
        'role' => $role,
        'email' => $this->option('email') ?: null,
        'is_active' => true,
    ]);
    $this->info("{$user->name}: {$role->label()}, id {$user->id}");
})->purpose('Завести пользователя (первого администратора) по ИИН');

Artisan::command('plan:auth-sync', function (AuthSync $sync) {
    foreach (User::orderBy('id')->get() as $user) {
        $this->line(sprintf('%s %s: %s', $user->iin, $user->name, $sync->push($user) ? 'ok' : 'ошибка (см. laravel.log)'));
    }
})->purpose('Передать всех пользователей в auth.his.kz (привязки к системе)');

Artisan::command('plan:mail-test {to}', function (string $to) {
    $this->line('Транспорт: '.config('mail.default').', отправитель: '.config('mail.from.address'));
    Mail::raw('Проверка почты '.config('app.name').' – '.now()->format('d.m.Y H:i'), fn ($m) => $m->to($to)->subject(config('app.name').': проверка почты'));
    $this->info("Отправлено на {$to}");
})->purpose('Тестовое письмо – проверить MAIL_* после выката');

Artisan::command('plan:settings-import', function () {
    $n = Settings::import();
    $this->info("Перенесено в БД из .env: {$n}");
})->purpose('Переложить настройки из .env в БД (только те, что еще не заданы в разделе Настройки)');

Schedule::command('hr:sync')->dailyAt('05:30')->withoutOverlapping();
