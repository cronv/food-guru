<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Commerce\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ручная корректировка баланса — только администратор. Например, возврат
 * за испорченный товар. Причина обязательна и попадает в историю покупателя.
 */
class AdminWalletController
{
    public function __construct(private WalletService $wallet) {}

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => [
            'balance' => $this->wallet->balance($user),
            'transactions' => WalletTransaction::where('user_id', $user->id)->orderByDesc('id')->limit(50)->get()->map->toApi(),
        ]]);
    }

    public function adjust(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            // Копейки, со знаком: плюс — зачислить, минус — списать.
            'amount' => ['required', 'integer', 'not_in:0', 'between:-10000000,10000000'],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ], ['reason.required' => 'Напишите причину — её увидит покупатель.']);

        $tx = $data['amount'] > 0
            ? $this->wallet->credit($user, $data['amount'], WalletTransaction::ADJUSTMENT, $data['reason'], by: $request->user())
            : $this->wallet->debit($user, -$data['amount'], WalletTransaction::ADJUSTMENT, $data['reason'], by: $request->user());

        ActivityLog::record($request->user(), 'wallet.adjusted', $user, [
            'email' => $user->email, 'amount' => $data['amount'], 'reason' => $data['reason'],
        ]);

        return response()->json(['data' => ['balance' => $tx->balance_after, 'transaction' => $tx->toApi()]]);
    }
}
