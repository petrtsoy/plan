<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Настройки системы из раздела "Настройки": ключи интеграций, адреса, почта,
 * логотип. Хранятся в таблице settings и поверх .env ложатся в config(),
 * поэтому код читает их как обычно – config('services.sso.client_id').
 * Нет записи в БД – действует значение из .env.
 */
class Settings
{
    /** Разделы страницы настроек. */
    public const GROUPS = [
        'brand' => 'Оформление',
        'sso' => 'Вход через auth.his.kz',
        'hr' => 'HR',
        'onec' => '1С',
        'mail' => 'Почта',
        'alerts' => 'Сигналы',
    ];

    // Логотип хранится в БД целиком, поэтому небольшой.
    public const LOGO_MAX_KB = 256;

    /** Значения из .env до наложения БД: их переносит import(). */
    private static array $env = [];

    /**
     * Каталог: ключ => [раздел, подпись, тип, путь в config, подсказка, доп.].
     * Типы: text, url, secret (шифруется, не показывается), bool, int, emails, select, image.
     */
    public static function definitions(): array
    {
        return [
            'brand.name' => ['brand', 'Название системы', 'text', 'app.name', 'В заголовке окна, меню, на входе и в письмах'],
            'brand.tagline' => ['brand', 'Подзаголовок на странице входа', 'text', 'app.tagline', null],
            'brand.logo' => ['brand', 'Логотип', 'image', 'app.logo', 'PNG, JPG, SVG или WebP до '.self::LOGO_MAX_KB.' КБ, квадратный. Без логотипа – значок "П"'],

            'sso.enabled' => ['sso', 'Использовать', 'bool', 'services.sso.enabled', null],
            'sso.auth_url' => ['sso', 'Адрес auth.his.kz', 'url', 'services.sso.auth_url', null],
            'sso.client_id' => ['sso', 'Client ID', 'text', 'services.sso.client_id', null],
            'sso.client_secret' => ['sso', 'Client secret', 'secret', 'services.sso.client_secret', 'Им же подписан обратный выход'],
            'sso.redirect_uri' => ['sso', 'Адрес возврата', 'url', 'services.sso.redirect_uri', 'Как в карточке клиента auth.his.kz'],
            'sso.system_code' => ['sso', 'Код системы в auth.his.kz', 'text', 'services.sso.system_code', null],

            'hr.base_url' => ['hr', 'Адрес HR', 'url', 'services.hr.base_url', null],
            'hr.api_key' => ['hr', 'Ключ API', 'secret', 'services.hr.api_key', 'Ключ потребителя с галочкой "it.his.kz: сотрудники и подразделения"'],
            'hr.timeout' => ['hr', 'Таймаут запроса, сек', 'int', 'services.hr.timeout', null, ['min' => 5, 'max' => 300]],

            'onec.base_url' => ['onec', 'Адрес базы 1С', 'url', 'services.onec.base_url', 'Корень публикации, без /odata/standard.odata/'],
            'onec.username' => ['onec', 'Пользователь', 'text', 'services.onec.username', 'Отдельный пользователь 1С с правом только на чтение'],
            'onec.password' => ['onec', 'Пароль', 'secret', 'services.onec.password', null],
            'onec.timeout' => ['onec', 'Таймаут запроса, сек', 'int', 'services.onec.timeout', 'Обход справочника идет страницами, запас нужен', ['min' => 5, 'max' => 600]],

            'mail.mailer' => ['mail', 'Отправка', 'select', 'mail.default', null, ['options' => ['smtp' => 'SMTP', 'log' => 'Только в лог (не отправлять)']]],
            'mail.host' => ['mail', 'SMTP-сервер', 'text', 'mail.mailers.smtp.host', 'На сервере – 127.0.0.1 (локальный Postfix)'],
            'mail.port' => ['mail', 'Порт', 'int', 'mail.mailers.smtp.port', null, ['min' => 1, 'max' => 65535]],
            'mail.scheme' => ['mail', 'Шифрование', 'select', 'mail.mailers.smtp.scheme', null, ['options' => ['' => 'по порту (465 – SSL/TLS)', 'smtp' => 'smtp (STARTTLS, если есть)', 'smtps' => 'smtps (SSL/TLS)']]],
            'mail.username' => ['mail', 'Логин', 'text', 'mail.mailers.smtp.username', null],
            'mail.password' => ['mail', 'Пароль', 'secret', 'mail.mailers.smtp.password', null],
            'mail.from_address' => ['mail', 'Адрес отправителя', 'email', 'mail.from.address', null],
            'mail.from_name' => ['mail', 'Имя отправителя', 'text', 'mail.from.name', null],

            'alerts.emails' => ['alerts', 'Получатели ежедневной сводки', 'emails', 'services.alerts.emails', 'Адреса через запятую'],
            'alerts.maintenance_days' => ['alerts', 'Предупредить о сроке ТО и поверки за, дней', 'int', 'services.alerts.maintenance_days', null, ['min' => 1, 'max' => 365]],
            'alerts.warranty_days' => ['alerts', 'Предупредить о конце гарантии за, дней', 'int', 'services.alerts.warranty_days', null, ['min' => 1, 'max' => 365]],
        ];
    }

    /** Значения из БД – поверх config(). Без таблицы (до миграции) – ничего не делает. */
    public static function apply(): void
    {
        try {
            $rows = Setting::pluck('value', 'key');
        } catch (\Throwable) {
            return;
        }

        foreach (self::definitions() as $key => $def) {
            if (! $rows->has($key)) {
                continue;
            }
            $value = self::fromStored($def[2], $rows[$key]);
            if ($def[2] === 'secret' && $rows[$key] !== null && $value === null) {
                continue; // не расшифровался (сменили APP_KEY) – остается .env
            }
            if (! array_key_exists($key, self::$env)) {
                self::$env[$key] = config($def[3]);
            }
            config([$def[3] => $value]);
        }

        $logo = config('app.logo');
        config(['app.logo_version' => $logo ? substr(md5($logo), 0, 8) : null]);
    }

    /** Разделы с полями и текущими значениями для страницы. Секреты – только "задан / нет". */
    public static function form(): array
    {
        $stored = Setting::pluck('key')->flip();
        $groups = [];
        foreach (self::definitions() as $key => $def) {
            [$group, $label, $type, $path, $hint] = $def;
            $value = config($path);
            $groups[$group][] = [
                'key' => $key,
                'field' => self::field($key),
                'label' => $label,
                'type' => $type,
                'hint' => $hint,
                'extra' => $def[5] ?? [],
                'value' => in_array($type, ['secret', 'image'], true) ? null : self::display($type, $value),
                'is_set' => filled($value),
                'from_db' => $stored->has($key),
            ];
        }

        return $groups;
    }

    /** Правила проверки полей раздела. */
    public static function rules(string $group): array
    {
        $rules = [];
        foreach (self::of($group) as $key => $def) {
            $field = self::field($key);
            $extra = $def[5] ?? [];
            $rules[$field] = match ($def[2]) {
                'url' => ['nullable', 'url', 'max:191'],
                'date' => ['nullable', 'date_format:Y-m-d'],
                'email' => ['nullable', 'email', 'max:191'],
                'secret' => ['nullable', 'string', 'max:500'],
                'bool' => ['boolean'],
                'int' => ['required', 'integer', 'min:'.($extra['min'] ?? 0), 'max:'.($extra['max'] ?? PHP_INT_MAX)],
                'emails' => ['nullable', 'string', 'max:1000', function ($attr, $value, $fail) {
                    foreach (self::splitEmails($value) as $email) {
                        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $fail("Неверный адрес: {$email}");
                        }
                    }
                }],
                'select' => ['nullable', Rule::in(array_map('strval', array_keys($extra['options'])))],
                'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:'.self::LOGO_MAX_KB],
                default => ['nullable', 'string', 'max:191'],
            };
        }

        return $rules;
    }

    /**
     * Сохранить раздел. Пустой секрет – оставить прежний, очистить – галочкой.
     * В журнал – разница значений, для секретов и логотипа только факт смены.
     */
    public static function save(string $group, Request $request): int
    {
        $changes = [];
        foreach (self::of($group) as $key => $def) {
            $type = $def[2];
            $field = self::field($key);
            $old = config($def[3]);

            if ($type === 'secret' || $type === 'image') {
                $new = match (true) {
                    $request->boolean($field.'_clear') => null,
                    $type === 'secret' && $request->boolean($field.'_generate') => Str::random(48),
                    $type === 'secret' && filled($request->input($field)) => trim((string) $request->input($field)),
                    $type === 'image' && $request->file($field) instanceof UploadedFile => self::dataUri($request->file($field)),
                    default => $old,
                };
                if ($new !== $old) {
                    $changes[$key] = [filled($old) ? 'задан' : 'нет', filled($new) ? 'новый' : 'нет'];
                }
            } else {
                $raw = $type === 'bool' ? $request->boolean($field) : $request->input($field);
                $new = self::fromStored($type, self::toStored($type, $raw));
                if (self::display($type, $new) !== self::display($type, $old)) {
                    $changes[$key] = [self::display($type, $old), self::display($type, $new)];
                }
            }

            Setting::updateOrCreate(['key' => $key], ['value' => self::toStored($type, $new)]);
        }

        if ($changes) {
            Audit::log('setting', 0, 'updated', $changes, self::GROUPS[$group]);
        }
        self::apply();

        return count($changes);
    }

    /**
     * Перенести в БД то, что задано в .env: ключа в БД нет или там пусто. Пустое в .env
     * не переносится – иначе оно закроет значение, которое в .env впишут позже.
     * $overwrite – ключи или разделы ("sso"), которые берутся из .env, даже если в БД уже есть.
     */
    public static function import(array $overwrite = []): int
    {
        $stored = Setting::pluck('value', 'key');
        $count = 0;
        foreach (self::definitions() as $key => $def) {
            $value = array_key_exists($key, self::$env) ? self::$env[$key] : config($def[3]);
            if ($value === null || $value === false || $value === '' || $value === []) {
                continue;
            }
            $forced = in_array($key, $overwrite, true) || in_array($def[0], $overwrite, true);
            if (! $forced && $stored->has($key) && ! in_array($stored[$key], [null, ''], true)) {
                continue;
            }
            Setting::updateOrCreate(['key' => $key], ['value' => self::toStored($def[2], $value)]);
            $count++;
        }
        if ($count) {
            Audit::log('setting', 0, 'updated', ['перенесено из .env' => $count]);
        }
        self::apply();

        return $count;
    }

    /** Логотип: [mime, байты] или null. */
    public static function logo(): ?array
    {
        $uri = config('app.logo');
        if (! $uri || ! preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $uri, $m)) {
            return null;
        }

        return [$m[1], base64_decode($m[2])];
    }

    private static function of(string $group): array
    {
        return array_filter(self::definitions(), fn ($def) => $def[0] === $group);
    }

    /** Точки в имени поля Laravel понимает как вложенность. */
    private static function field(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    private static function toStored(string $type, mixed $value): ?string
    {
        return match (true) {
            $type === 'bool' => $value ? '1' : '0',
            $type === 'emails' => implode(', ', is_array($value) ? $value : self::splitEmails($value)),
            $value === null || $value === '' => null,
            $type === 'secret' => Crypt::encryptString((string) $value),
            default => trim((string) $value),
        };
    }

    /** Строка из БД (или из формы через toStored) – значение для config(), как в config/*.php. */
    private static function fromStored(string $type, ?string $value): mixed
    {
        if ($type === 'secret') {
            try {
                return $value === null ? null : Crypt::decryptString($value);
            } catch (DecryptException) {
                return null;
            }
        }

        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'emails' => self::splitEmails($value),
            'url' => $value === null ? null : rtrim($value, '/'),
            default => $value === '' ? null : $value,
        };
    }

    private static function display(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => (bool) $value,
            'emails' => implode(', ', (array) $value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function splitEmails(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $value))));
    }

    private static function dataUri(UploadedFile $file): string
    {
        $mime = $file->getMimeType() === 'image/svg' ? 'image/svg+xml' : $file->getMimeType();

        return 'data:'.$mime.';base64,'.base64_encode($file->get());
    }
}
