# Вход через auth.his.kz и регистрация системы

Код системы везде один – `plan`: `system_code` клиента в auth.his.kz, код в реестре
доступа it.his.kz и в справочнике систем HR.

## 1. Клиент в auth.his.kz

Админка auth – "Клиенты" – "Добавить" (`AdminController::addClient`; в базе хранится
sha256 секрета, сам секрет – только в plan):

| Поле | production | локально |
|---|---|---|
| client_id | `plan` | `plan-local` |
| Название | Оборудование на планах | plan (локально) |
| system_code | `plan` | `plan` |
| base_url | `https://plan.his.kz` | `http://localhost:8010` |
| redirect_uris | `["https://plan.his.kz/auth/sso/callback"]` | `["http://localhost:8010/auth/sso/callback"]` |
| allowed_domains | `["plan.his.kz"]` | `["localhost"]` |
| logout_uri | `https://plan.his.kz/auth/sso/logout` | `http://localhost:8010/auth/sso/logout` |
| client_secret | длинная случайная строка, копия – в Vaultwarden | любая |

Сверка доступов (`recon_url`) пока не нужна: снимка доступов у plan еще нет.

## 2. Настройки plan

"Настройки" – "Вход через auth.his.kz": включить, адрес auth, client_id, client secret
(тот же, что в auth), адрес возврата, код системы `plan`. Первый администратор:

```
php artisan plan:user <ИИН> "<ФИО>" --role=admin
```

Пользователи, заведенные в plan, сами передаются в auth (`AuthSync`, `POST /api/sync/user`):
без этой привязки auth не пустит человека в систему. Повторить для всех – `plan:auth-sync`.

## 3. Локальный запуск

auth рассчитан на корень сайта (Router без базового пути), поэтому локально – встроенным
сервером PHP, без правки Apache и hosts. `.claude/launch.json`:

- `plan` – `php artisan serve --port=8010`, `APP_URL=http://localhost:8010`;
- `auth` – `php -S localhost:8090 -t ../auth/public ../auth/public/index.php`;
  в настройках plan адрес auth – `http://localhost:8090`.

Локальному auth нужна своя база (`auth/.env`, `DB_*`) с примененными миграциями
(`php migrate.php` в папке auth) и пользователь с ИИН администратора plan.

## 4. Реестр доступа it.his.kz

"Сервисы" – "Добавить систему": slug `plan`, домен `plan.his.kz`, площадка hems
(hr-supply), Laravel 13, git `https://github.com/petrtsoy/plan`. На карточке – "Доступ для
заявок": код `plan`, выдача `sso`, отзыв `it_queue`, роли: Администратор, Инженер,
Бухгалтер, Просмотр (как `App\Enums\Role`).

## 5. Справочник систем HR

Миграция `hr/db/migrations/20261114_0001_plan_system.sql` (`php migrate.php` в HR):
строка `dict_it_systems` с кодом `plan` и четыре роли. Когда HR начнет забирать реестр
из it.his.kz (`it/docs/api-hr-systems.md`), строки сопоставятся по коду и названиям ролей.
