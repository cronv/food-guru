<?php

namespace App\Services\Commerce;

use App\Models\Payment;

/**
 * Платёжная система. Подключить настоящую (ЮKassa, CloudPayments, Т-Банк) —
 * значит написать ещё одну реализацию и выбрать её в config/payments.php:
 * create() создаёт платёж у провайдера и возвращает ссылку на его форму,
 * а вебхук провайдера после проверки подписи вызывает PaymentService::succeed().
 */
interface PaymentGateway
{
    /** Код провайдера, пишется в payments.provider. */
    public function code(): string;

    /** Создать платёж у провайдера. Возвращает [id у провайдера, адрес страницы оплаты]. */
    public function create(Payment $payment): array;
}
