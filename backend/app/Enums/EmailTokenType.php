<?php

namespace App\Enums;

enum EmailTokenType: string
{
    /** Подтверждение адреса при регистрации или смене почты. */
    case Verify = 'verify';

    /** Ссылка для входа в уже подтверждённый аккаунт. */
    case Login = 'login';

    public function ttl(): \DateInterval
    {
        return match ($this) {
            self::Verify => new \DateInterval('PT'.config('food_guru.tokens.verify_ttl_hours').'H'),
            self::Login => new \DateInterval('PT'.config('food_guru.tokens.login_ttl_minutes').'M'),
        };
    }
}
