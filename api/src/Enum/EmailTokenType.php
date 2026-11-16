<?php

declare(strict_types=1);

namespace App\Enum;

enum EmailTokenType: string
{
    case Verify = 'verify';
    case Login = 'login';

    public function ttl(): \DateInterval
    {
        return match ($this) {
            self::Verify => new \DateInterval('PT24H'),
            self::Login => new \DateInterval('PT15M'),
        };
    }
}
