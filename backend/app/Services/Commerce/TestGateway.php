<?php

namespace App\Services\Commerce;

use App\Models\Payment;

/**
 * Тестовая платёжная система: деньги не списываются. Страница оплаты — на сайте
 * (/wallet/pay/{id}), где покупатель жмёт «Оплатить» или «Отменить». Дальше
 * всё идёт тем же путём, что у настоящего провайдера: PaymentService::succeed().
 */
class TestGateway implements PaymentGateway
{
    /**
     * В production тестовая оплата запрещена всегда — даже если кто-то забыл
     * поменять PAYMENT_PROVIDER. Включить её там нельзя никакой настройкой.
     */
    public static function forbidden(): bool
    {
        return config('app.env') === 'production';
    }

    public function code(): string
    {
        return 'test';
    }

    public function create(Payment $payment): array
    {
        $site = rtrim((string) config('food_guru.site_url'), '/');

        return ['test_'.$payment->id, "{$site}/wallet/pay/{$payment->id}"];
    }
}
