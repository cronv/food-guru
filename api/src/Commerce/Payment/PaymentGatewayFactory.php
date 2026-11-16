<?php

declare(strict_types=1);

namespace App\Commerce\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Платёжная система по PAYMENT_PROVIDER.
 * Нет годного провайдера — null: тогда пополнение
 * отвечает 503, а остальной кошелёк работает.
 */
final class PaymentGatewayFactory
{
    public function __construct(
        #[Autowire(env: 'PAYMENT_PROVIDER')]
        private readonly string $provider,
        #[Autowire(env: 'SITE_URL')]
        private readonly string $siteUrl,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function gateway(): ?PaymentGateway
    {
        return match ($this->provider) {
            // Тестовая оплата зачисляет деньги по кнопке «Оплатить» — в проде это
            // бесплатный баланс для любого. Поэтому там её нет.
            'test' => TestGateway::forbidden($this->environment) ? null : new TestGateway($this->siteUrl),
            default => null,
        };
    }

    public function testMode(): bool
    {
        return 'test' === $this->provider && !TestGateway::forbidden($this->environment);
    }
}
