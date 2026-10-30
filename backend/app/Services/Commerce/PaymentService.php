<?php

namespace App\Services\Commerce;

use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/** Пополнение кошелька: создать платёж, подтвердить, отменить. */
class PaymentService
{
    public function __construct(
        private PaymentGateway $gateway,
        private WalletService $wallet,
    ) {}

    public function createTopUp(User $user, int $amount, string $method): Payment
    {
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'method' => $method,
            'provider' => $this->gateway->code(),
            'status' => Payment::PENDING,
        ]);

        [$providerId, $url] = $this->gateway->create($payment);

        $payment->forceFill(['provider_payment_id' => $providerId, 'confirmation_url' => $url])->save();

        return $payment;
    }

    /**
     * Платёж прошёл: зачислить деньги. Безопасно вызывать повторно — провайдеры
     * присылают уведомление несколько раз, а зачисление должно быть одно.
     */
    public function succeed(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status === Payment::SUCCEEDED) {
                return $payment;
            }
            if ($payment->status !== Payment::PENDING) {
                throw new \DomainException('Платёж уже отменён.');
            }

            $payment->forceFill(['status' => Payment::SUCCEEDED, 'paid_at' => now()])->save();

            $this->wallet->credit(
                $payment->user,
                $payment->amount,
                WalletTransaction::TOPUP,
                'Пополнение: '.(Payment::METHODS[$payment->method] ?? $payment->method),
                paymentId: $payment->id,
            );

            return $payment;
        });
    }

    public function cancel(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status === Payment::PENDING) {
                $payment->forceFill(['status' => Payment::CANCELED, 'canceled_at' => now()])->save();
            }

            return $payment;
        });
    }
}
