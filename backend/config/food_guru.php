<?php

return [
    // Адреса фронтендов — используются при сборке ссылок в письмах.
    'site_url' => env('SITE_URL', env('APP_URL', 'http://localhost:4200')),
    'admin_url' => env('ADMIN_URL', 'http://localhost:4300'),

    // Общий секрет с SSR-сервером сайта. Он рендерит страницы за всех посетителей
    // с одного IP, поэтому присылает ключ и адрес посетителя, и лимит запросов
    // считается по посетителю. Пусто — заголовкам не верим.
    'ssr_key' => env('SSR_API_KEY'),

    'tokens' => [
        // Ссылка подтверждения адреса.
        'verify_ttl_hours' => (int) env('VERIFY_TOKEN_TTL_HOURS', 24),
        // Ссылка входа — живёт заметно меньше.
        'login_ttl_minutes' => (int) env('LOGIN_TOKEN_TTL_MINUTES', 15),
        // Минимальный интервал между письмами на один адрес.
        'resend_cooldown_seconds' => 60,
    ],

    'payments' => [
        // test — тестовая оплата без денег. Настоящий провайдер добавляется
        // реализацией App\Services\Commerce\PaymentGateway и своим кодом здесь.
        'provider' => env('PAYMENT_PROVIDER', 'test'),
        // Пополнение, в копейках: от 100 до 50 000 ₽.
        'topup_min' => 10000,
        'topup_max' => 5000000,
    ],
];
