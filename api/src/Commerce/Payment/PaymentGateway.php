<?php

declare(strict_types=1);

namespace App\Commerce\Payment;

/**
 * Платёжная система. Подключить настоящую (ЮKassa, CloudPayments, Т-Банк) — значит
 * написать ещё одну реализацию и выбрать её в PaymentGatewayFactory: create()
 * создаёт платёж у провайдера и возвращает ссылку на его форму, а вебхук
 * провайдера после проверки подписи вызывает PaymentService::succeed().
 */
interface PaymentGateway
{
    /** Код провайдера, пишется в payments.provider. */
    public function code(): string;

    /**
     * Создать платёж у провайдера.
     *
     * @return array{0: string, 1: string} [id у провайдера, адрес страницы оплаты]
     */
    public function create(string $paymentId, int $amount): array;
}
