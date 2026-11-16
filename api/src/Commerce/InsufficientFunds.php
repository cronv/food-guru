<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Api\ApiError;

/** На балансе меньше, чем стоит покупка. 402 с суммой, которой не хватает. */
final class InsufficientFunds extends ApiError
{
    public function __construct(public readonly int $needed, public readonly int $balance)
    {
        parent::__construct(402, 'На балансе не хватает денег. Пополните кошелёк и повторите.', 'insufficient_funds', [
            'balance' => $balance,
            'needed' => $needed,
            'shortfall' => max(0, $needed - $balance),
        ]);
    }
}
