<?php

namespace App\Services\Commerce;

use App\Models\BookPurchase;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Повторная покупка той же книги. Транзакция откатывается, деньги не списаны. */
class AlreadyOwned extends RuntimeException
{
    public function __construct(public readonly BookPurchase $purchase)
    {
        parent::__construct('Книга уже куплена.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => 'Эта книга уже у вас.', 'code' => 'already_owned'], 409);
    }
}
