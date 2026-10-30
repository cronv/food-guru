<?php

/*
 * Вход держится на cookie-сессии Sanctum, поэтому фронтенды шлют запросы
 * с credentials. Браузер такой ответ принимает, только если в нём назван
 * конкретный адрес и разрешены credentials, — звёздочку он отвергает.
 * Значение Laravel по умолчанию ('*' без credentials) блокирует весь API.
 *
 * Адреса те же, что для ссылок в письмах: SITE_URL и ADMIN_URL в .env.
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        rtrim((string) env('SITE_URL', 'http://localhost:4200'), '/'),
        rtrim((string) env('ADMIN_URL', 'http://localhost:4300'), '/'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Retry-After — чтобы форма входа могла сказать «попробуйте через 12 минут».
    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,
];
