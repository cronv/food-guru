<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Models\WalletTransaction;
use App\Services\Commerce\PaymentService;
use App\Services\Commerce\TestGateway;
use App\Services\Commerce\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController
{
    public function __construct(private WalletService $wallet) {}

    /**
     * Платёжный сервис берём по требованию: если провайдер не настроен
     * (в проде без PAYMENT_PROVIDER), падает только пополнение, а не весь кошелёк.
     */
    private function payments(bool $report = true): ?PaymentService
    {
        try {
            return app(PaymentService::class);
        } catch (\RuntimeException $e) {
            if ($report) {
                report($e);
            }

            return null;
        }
    }

    /** Баланс и история операций. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $history = WalletTransaction::where('user_id', $user->id)->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => [
                'balance' => $this->wallet->balance($user),
                'transactions' => $history->getCollection()->map->toApi(),
            ],
            'meta' => [
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'total' => $history->total(),
            ],
        ]);
    }

    /** Способы пополнения и пределы суммы — форма берёт их отсюда, а не держит у себя. */
    public function methods(): JsonResponse
    {
        return response()->json(['data' => [
            'methods' => collect(Payment::METHODS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'min' => config('food_guru.payments.topup_min'),
            'max' => config('food_guru.payments.topup_max'),
            'test_mode' => config('food_guru.payments.provider') === 'test' && ! TestGateway::forbidden(),
            // Без записи в лог: страницу кошелька открывают часто, а «шлюза пока нет» — не сбой.
            'available' => $this->payments(report: false) !== null,
        ]]);
    }

    /** Начать пополнение: создаём платёж и отдаём адрес страницы оплаты. */
    public function topUp(Request $request): JsonResponse
    {
        $min = config('food_guru.payments.topup_min');
        $max = config('food_guru.payments.topup_max');

        $data = $request->validate([
            // Сумма в копейках, целым числом.
            'amount' => ['required', 'integer', "min:{$min}", "max:{$max}"],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
        ], [
            'amount.min' => 'Минимальное пополнение — '.intdiv($min, 100).' ₽.',
            'amount.max' => 'За один раз можно пополнить не больше чем на '.number_format(intdiv($max, 100), 0, ',', ' ').' ₽.',
            'method.in' => 'Выберите способ пополнения.',
        ]);

        $payments = $this->payments();
        if (! $payments) {
            return response()->json(['message' => 'Пополнение временно недоступно. Попробуйте позже.'], 503);
        }

        $payment = $payments->createTopUp($request->user(), $data['amount'], $data['method']);

        return response()->json(['data' => $payment->toApi()], 201);
    }

    /** Статус платежа — для страницы, куда покупатель возвращается после оплаты. */
    public function payment(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        return response()->json(['data' => $payment->toApi() + ['balance' => $this->wallet->balance($request->user())]]);
    }

    /**
     * Ответ тестовой платёжной системы: «оплатить» или «отменить». Работает только
     * для платежей тестового провайдера — настоящий платёж так не подтвердить.
     */
    public function testComplete(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 404);
        abort_unless($payment->provider === 'test', 404);
        // Вторая защита: даже тестовый платёж, созданный когда-то раньше, в production не подтвердить.
        abort_if(TestGateway::forbidden(), 404);

        $data = $request->validate(['result' => ['required', 'in:succeeded,canceled']]);

        if ($payment->status !== Payment::PENDING) {
            return response()->json(['message' => 'Этот платёж уже завершён.'], 409);
        }

        $payments = $this->payments() ?? abort(404);
        $payment = $data['result'] === 'succeeded'
            ? $payments->succeed($payment)
            : $payments->cancel($payment);

        return response()->json(['data' => $payment->toApi() + ['balance' => $this->wallet->balance($request->user())]]);
    }
}
