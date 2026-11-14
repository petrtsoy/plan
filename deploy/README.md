# Развертывание plan.his.kz

Сервер hr-supply (`ubuntu@hems.kz`, 94.247.128.188), рядом с hr, supply и it: Ubuntu 24.04,
PHP 8.3 (php-fpm), MySQL 8.0, Apache 2.4. Приложение работает от `ubuntu`, каталог
`/var/www/plan`, логи `/var/log/plan`. Сборки фронтенда нет. Порядок повторяет
`it/deploy/README.md`.

До начала:
- DNS `plan.his.kz` -> 94.247.128.188 (на 14.11.2026 записи нет);
- клиент на auth.his.kz ("Админ -> Клиенты") – поля в `docs/sso-and-registry.md`, раздел 1;
- ключ потребителя в HR для филиалов и сотрудников (как у it, `hr/docs/api-it.md`).

## 1. Код и каталоги

```bash
sudo mkdir -p /var/www/plan /var/log/plan && sudo chown ubuntu:ubuntu /var/www/plan /var/log/plan
git clone git@github-plan:petrtsoy/plan.git /var/www/plan
```

Репозиторий приватный. Deploy-ключ – как у it: свой ключ `~/.ssh/plan_deploy`, хост
`github-plan` в `~/.ssh/config` (по образцу `github-it`), публичная часть – в
GitHub: petrtsoy/plan – Settings – Deploy keys (только чтение).

## 2. База

```bash
sudo mysql --defaults-file=/etc/mysql/debian.cnf <<SQL
CREATE DATABASE IF NOT EXISTS plan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'plan'@'localhost'  IDENTIFIED BY 'ПАРОЛЬ';
CREATE USER IF NOT EXISTS 'plan'@'127.0.0.1' IDENTIFIED BY 'ПАРОЛЬ';
GRANT ALL PRIVILEGES ON plan.* TO 'plan'@'localhost';
GRANT ALL PRIVILEGES ON plan.* TO 'plan'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

Пароль – `openssl rand -hex 24`, копия в Vaultwarden.

## 3. .env

```bash
cd /var/www/plan && cp .env.example .env && chmod 600 .env
```

| Переменная | Значение |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://plan.his.kz` |
| `LOG_LEVEL` | `warning` |
| `DB_CONNECTION` | `mysql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=plan`, `DB_USERNAME=plan`, `DB_PASSWORD` из шага 2 |
| `SESSION_SECURE_COOKIE` | `true` |
| `MAIL_MAILER` / `MAIL_SCHEME` | `smtp` / `smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=25` – локальный Postfix |
| `MAIL_FROM_ADDRESS` | `noreply@hemcenter.kz` (его SPF разрешает этот сервер) |

SSO, HR, почту и сигналы удобнее внести после первого входа в разделе "Настройки"
(значения из БД главнее `.env`). Заданные в `.env` перенесет `php artisan plan:settings-import`.

## 4. Зависимости и база

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=CatalogSeeder --force
chmod -R ug+rwX storage bootstrap/cache
```

`CatalogSeeder` – справочник слоев и категорий, повторный запуск безопасен.
`db:seed` без класса не запускать – это демо (`DatabaseSeeder`).

## 5. php-fpm, Apache, сертификат

```bash
sudo cp deploy/php-fpm/plan.conf /etc/php/8.3/fpm/pool.d/ && sudo php-fpm8.3 -t && sudo systemctl reload php8.3-fpm
sed -n '1,/^<\/VirtualHost>/p' deploy/apache/plan.his.kz.conf | sudo tee /etc/apache2/sites-available/plan.his.kz.conf >/dev/null
sudo a2ensite plan.his.kz && sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot certonly --webroot -w /var/www/plan/public -d plan.his.kz
sudo cp deploy/apache/plan.his.kz.conf /etc/apache2/sites-available/ && sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot renew --cert-name plan.his.kz --dry-run
```

Сначала включается только блок `:80`: без сертификата блок `:443` не пройдет configtest.

## 6. Кеши, планировщик, логи

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
crontab -e        # строка из deploy/cron/plan.cron
sudo cp deploy/logrotate/plan /etc/logrotate.d/plan
sudo cp deploy/pull-plan /usr/local/bin/pull-plan && sudo chmod +x /usr/local/bin/pull-plan
```

Очередь не используется – воркер не нужен.

## 7. Первые данные

```bash
php artisan plan:user <ИИН> "<ФИО>" --role=admin
php artisan hr:sync --full
php artisan plan:mail-test ваш.адрес@hemcenter.kz
```

`plan:user` сам передает пользователя в auth (`AuthSync`), если SSO уже настроен;
иначе после настройки – `php artisan plan:auth-sync`.

## 8. Резервная копия

`/usr/local/bin/db-backup.sh plan`: конфиг `/etc/db-backup/plan.conf` по образцу
`it.conf` и строка в `/etc/cron.d/db-backup`. Чертежи (`storage/app`) – в ту же копию,
когда появится их загрузка (этап 1).

## Проверка

```bash
curl -sI https://plan.his.kz/ | head -1     # 302 на /login
tail -20 /var/log/plan/php-error.log
tail -20 /var/log/cron/plan_schedule.log
```

## Обновление

`pull-plan` от пользователя `ubuntu`.
