<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Токен для cookie XSRF-TOKEN, которую читает фронтенд и шлёт в X-XSRF-TOKEN.
 * Не хранится нигде: значение подписано секретом приложения, поэтому подделать
 * его без секрета нельзя, а сессия для проверки не нужна.
 */
final class Xsrf
{
    public const COOKIE = 'XSRF-TOKEN';
    public const HEADER = 'X-XSRF-TOKEN';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    public function issue(): string
    {
        $nonce = bin2hex(random_bytes(16));

        return $nonce.'.'.hash_hmac('sha256', $nonce, $this->secret);
    }

    public function isValid(string $token): bool
    {
        [$nonce, $signature] = array_pad(explode('.', $token, 2), 2, '');

        return '' !== $nonce && hash_equals(hash_hmac('sha256', $nonce, $this->secret), $signature);
    }
}
