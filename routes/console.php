<?php

use App\Enums\Role;
use App\Mail\MaintenanceDigest;
use App\Models\Office;
use App\Models\User;
use App\Services\AuthSync;
use App\Services\HrSync;
use App\Services\Maintenance;
use App\Services\OnecSync;
use App\Services\OutboxSender;
use App\Services\Settings;
use His\OnecOdata\OnecException;
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

Artisan::command('plan:settings-import {--overwrite=* : ключ или раздел (sso, hr, mail), который взять из .env, даже если он уже задан}', function () {
    $n = Settings::import($this->option('overwrite'));
    $this->info("Перенесено в БД из .env: {$n}");
})->purpose('Переложить настройки из .env в БД (только непустые и еще не заданные в разделе Настройки)');

Artisan::command('outbox:send {--limit= : сколько сообщений за раз}', function (OutboxSender $sender) {
    $stats = $sender->run($this->option('limit') ? (int) $this->option('limit') : null);
    $this->info(sprintf('Отправлено %d, повтор позже %d, не ушло совсем %d, ждет настройки %d',
        $stats['sent'], $stats['retry'], $stats['failed'], $stats['waiting']));
})->purpose('Отправить накопившееся в outbox в 1С и it.his.kz (с повторами)');

Artisan::command('plan:maintenance-digest {--dry-run : Показать сводку, но не отправлять}', function (Maintenance $maintenance) {
    $digest = $maintenance->digest();

    $this->line(sprintf('Просрочено %d, срок в ближайшие %d дней – %d, гарантия %d, договоры %d',
        $digest['overdue']->count(), $digest['days'], $digest['soon']->count(),
        $digest['warranty']->count(), $digest['contracts']->count()));

    // Пустую сводку не отправляем: письмо "все в порядке" каждое утро читать перестанут.
    if (! $digest['total']) {
        $this->info('Сигналов нет – письмо не отправляем.');

        return;
    }

    $to = config('services.alerts.emails');
    if (! $to) {
        $this->warn('Некому отправлять: получатели – раздел "Настройки" → "Сигналы".');

        return;
    }
    if ($this->option('dry-run')) {
        $this->info('--dry-run: не отправлено. Получатели: '.implode(', ', $to));

        return;
    }

    Mail::to($to)->send(new MaintenanceDigest($digest));
    $this->info('Отправлено: '.implode(', ', $to));
})->purpose('Ежедневная сводка: просроченные и близкие сроки ТО и поверки, гарантия, договоры');

Schedule::command('hr:sync')->dailyAt('05:30')->withoutOverlapping();

Artisan::command('onec:sync {--dry-run : только показать расхождения, ничего не записывать}
                            {--office= : только этот филиал (id)}', function (OnecSync $sync) {
    $officeId = $this->option('office') === null ? null : (int) $this->option('office');
    if ($officeId !== null && ! Office::whereKey($officeId)->exists()) {
        $this->error("Филиала с id {$officeId} нет.");

        return 1;
    }

    $dry = (bool) $this->option('dry-run');

    try {
        $report = $dry ? $sync->compare($officeId) : $sync->run($officeId);
    } catch (OnecException|RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->line(sprintf('В 1С строк: %d, живых карточек %d, отсеяно папками %d.', $report['rows'], $report['live'], $report['skipped']));
    $this->line('Совпало с plan: '.$report['matched'].'.');
    $this->info($dry
        ? 'Будет заведено: '.count($report['create'])
        : sprintf('Заведено %d, обновлено %d, списано %d.', $report['applied']['created'], $report['applied']['updated'], $report['applied']['written_off']));

    foreach ([
        'Расхождения по учетным полям (1С главнее)' => count($report['accounting']),
        'Расхождения по месту или МОЛ (не трогаем, разбирают руками)' => count($report['placement']),
        'Списано в 1С' => count($report['written_off']),
        'Инвентарный номер занят другой единицей' => count($report['conflicts']),
        'Есть только в 1С, филиал не определен' => count($report['no_office']),
        'В plan без ключа ОС' => $report['no_ref']->count(),
        'Ключ ОС есть, в 1С не нашлось' => $report['missing']->count(),
    ] as $what => $count) {
        $this->line($what.': '.$count);
    }

    // Чаще всего не хватает именно этого: подразделение 1С не сопоставлено с филиалом.
    foreach (array_slice($report['no_office'], 0, 10) as $card) {
        $this->comment('  '.$card['name'].' (подразделение '.($card['department_ref'] ?? 'не задано').')');
    }

    if ($dry) {
        $this->newLine();
        $this->comment('Это --dry-run: ничего не записано.');
    }

    return 0;
})->purpose('Зеркалить справочник основных средств из 1С (учетные поля; место и МОЛ не трогает)');

Artisan::command('onec:probe {catalog? : сущность OData, по умолчанию справочник ОС из config/onec.php}', function (OnecSync $sync) {
    // Имена реквизитов по памяти не угадать, а $select с несуществующим именем 1С отклоняет
    // целиком. Поэтому: одна карточка как есть – и видно, что вписать в config/onec.php.
    try {
        $row = $sync->probe($this->argument('catalog'));
    } catch (OnecException|RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    if ($row === []) {
        $this->warn('Справочник пуст – реквизиты показать не на чем.');

        return 0;
    }

    $this->table(['реквизит', 'значение первой карточки'], array_map(
        fn ($k, $v) => [$k, mb_strimwidth((string) $v, 0, 60, '...')], array_keys($row), $row
    ));

    return 0;
})->purpose('Показать состав реквизитов справочника 1С – имена для config/onec.php');

// После hr:sync и до сводки: филиалы для подразделений 1С уже свежие, а в сводке уже видно
// то, что приехало из 1С.
Schedule::command('onec:sync')->dailyAt('05:45')->withoutOverlapping();
// Утром, после hr:sync: в сводке уже новые филиалы и сотрудники.
Schedule::command('plan:maintenance-digest')->dailyAt('06:00')->withoutOverlapping();

// Отправка в 1С и it.his.kz: сообщение ставится в транзакции с перемещением, отсюда уходит.
// Повторы растут сами (OutboxSender), поэтому проверять очередь часто не дорого.
Schedule::command('outbox:send')->everyFiveMinutes()->withoutOverlapping();
