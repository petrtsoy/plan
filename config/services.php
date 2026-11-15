<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Папка pdftoppm и pdftotext (poppler-utils). Пусто – ищутся в PATH (на сервере – /usr/bin).
    'poppler' => [
        'bin' => env('POPPLER_BIN', ''),
    ],

    // Python с PyMuPDF для чистки подложки (scripts/pdf_clean.py). На сервере – python3 и python3-fitz.
    'python' => [
        'bin' => env('PYTHON_BIN', 'python3'),
    ],

    // Вход через SSO auth.his.kz (OAuth 2.0 Authorization Code). ЭЦП/пароль
    // проверяет сам сервер авторизации, сюда приходит ИИН в userinfo.
    'sso' => [
        'enabled' => (bool) env('SSO_ENABLED', false),
        'auth_url' => rtrim((string) env('SSO_AUTH_URL', 'https://auth.his.kz'), '/'),
        'client_id' => env('SSO_CLIENT_ID'),
        'client_secret' => env('SSO_CLIENT_SECRET'),
        'redirect_uri' => env('SSO_REDIRECT_URI'),
        // Код системы в auth.his.kz: по нему сервер находит связки и шлет выход.
        'system_code' => env('SSO_SYSTEM_CODE', 'plan'),
    ],

    // Сотрудники и филиалы из HR: те же /api/it/* (контракт hr/docs/api-it.md), ключ потребителя
    // с галочкой "it.his.kz: сотрудники и подразделения" – отдельный от ключа it.his.kz.
    'hr' => [
        'base_url' => rtrim((string) env('HR_BASE_URL', 'https://hr.his.kz'), '/'),
        'api_key' => env('HR_API_KEY'),
        'timeout' => (int) env('HR_TIMEOUT', 30),
    ],

    // Справочник основных средств 1С (только чтение, пакет his/onec-odata – как в hr и supply).
    // Адрес – корень публикации базы, без /odata/standard.odata/. Имена справочника и
    // реквизитов – не здесь, а в config/onec.php.
    'onec' => [
        'base_url' => rtrim((string) env('ONEC_BASE_URL', ''), '/'),
        'username' => env('ONEC_USERNAME'),
        'password' => env('ONEC_PASSWORD'),
        'timeout' => (int) env('ONEC_TIMEOUT', 60),
    ],

    // Обмен с it.his.kz: место ИТ-техники первично там (docs/it-exchange.md).
    // api_key – которым plan обращается туда, incoming_key – которым it.his.kz присылает
    // сюда события перемещений (POST /api/it/moves).
    'it' => [
        'base_url' => rtrim((string) env('IT_BASE_URL', 'https://it.his.kz'), '/'),
        'api_key' => env('IT_API_KEY'),
        'incoming_key' => env('IT_INCOMING_KEY'),
        'timeout' => (int) env('IT_TIMEOUT', 30),
    ],

    // Кому слать ежедневную сводку сигналов (через запятую) и за сколько дней предупреждать.
    'alerts' => [
        'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('PLAN_ALERT_EMAILS', ''))))),
        'maintenance_days' => (int) env('PLAN_MAINTENANCE_DAYS', 30),
        'warranty_days' => (int) env('PLAN_WARRANTY_DAYS', 30),
    ],

];
