<?php

declare(strict_types=1);

namespace App\Commerce\Payment;

/**
 * Тестовая платёжная система: деньги не списываются. Страница оплаты — на сайте
 * (/wallet/pay/{id}), где покупатель жмёт «Оплатить» или «Отменить». Дальше всё
 * идёт тем же путём, что у настоящего провайдера: PaymentService::succeed().
 */
final class TestGateway implements PaymentGateway
{
    public function __construct(private readonly string $siteUrl)
    {
    }

    /**
     * Тестовая оплата — только в разработке и тестах. Список разрешённых, а не
     * запрещённых окружений: при APP_ENV=production,
     * staging или опечатке она тоже выключена. Иначе кнопка «Оплатить» на боевом
     * сервере зачисляла бы любому бесплатный баланс.
     */
    public static function forbidden(string $kernelEnvironment): bool
    {
        return !\in_array($kernelEnvironment, ['dev', 'test'], true);
    }

    public function code(): string
    {
        return 'test';
    }

    public function create(string $paymentId, int $amount): array
    {
        return ['test_'.$paymentId, rtrim($this->siteUrl, '/')."/wallet/pay/{$paymentId}"];
    }
}
