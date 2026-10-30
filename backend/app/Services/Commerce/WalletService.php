<?php

namespace App\Services\Commerce;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Единственное место, где меняется баланс.
 *
 * Каждое движение — проводка в wallet_transactions и изменение wallets.balance
 * в одной транзакции под блокировкой строки кошелька. Два одновременных
 * списания не уведут баланс в минус: второе дождётся первого и увидит остаток.
 */
class WalletService
{
    public function balance(User $user): int
    {
        return (int) (Wallet::find($user->id)->balance ?? 0);
    }

    /** Зачислить. Повторный вызов с тем же платежом ничего не делает. */
    public function credit(
        User $user,
        int $amount,
        string $type,
        string $description,
        ?Model $subject = null,
        ?string $paymentId = null,
        ?User $by = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма зачисления должна быть больше нуля.');
        }

        return $this->move($user, $amount, $type, $description, $subject, $paymentId, $by);
    }

    /** Списать. Не хватает денег — InsufficientFunds, баланс не трогается. */
    public function debit(
        User $user,
        int $amount,
        string $type,
        string $description,
        ?Model $subject = null,
        ?User $by = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма списания должна быть больше нуля.');
        }

        return $this->move($user, -$amount, $type, $description, $subject, null, $by);
    }

    private function move(
        User $user,
        int $amount,
        string $type,
        string $description,
        ?Model $subject,
        ?string $paymentId,
        ?User $by,
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $type, $description, $subject, $paymentId, $by) {
            Wallet::query()->insertOrIgnore(['user_id' => $user->id, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $wallet = Wallet::whereKey($user->id)->lockForUpdate()->first();

            if ($paymentId && ($done = WalletTransaction::where('payment_id', $paymentId)->first())) {
                return $done;
            }

            $after = $wallet->balance + $amount;

            if ($after < 0) {
                throw new InsufficientFunds(-$amount, $wallet->balance);
            }

            $wallet->forceFill(['balance' => $after])->save();

            return WalletTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $after,
                'type' => $type,
                'description' => mb_substr($description, 0, 255),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'payment_id' => $paymentId,
                'created_by' => $by?->id,
            ]);
        });
    }
}
