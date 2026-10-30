<?php

namespace App\Services\Commerce;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** На балансе меньше, чем стоит покупка. Отвечает 402 с суммой, которой не хватает. */
class InsufficientFunds extends RuntimeException
{
    public function __construct(public readonly int $needed, public readonly int $balance)
    {
        parent::__construct('Недостаточно средств на балансе.');
    }

    public function shortfall(): int
    {
        return max(0, $this->needed - $this->balance);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'На балансе не хватает денег. Пополните кошелёк и повторите.',
            'code' => 'insufficient_funds',
            'balance' => $this->balance,
            'needed' => $this->needed,
            'shortfall' => $this->shortfall(),
        ], 402);
    }
}
