<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Commerce\Checkout;
use App\Commerce\Payment\PaymentGatewayFactory;
use PHPUnit\Framework\TestCase;

final class MoneyRulesTest extends TestCase
{
    public function testMonthsAreAddedWithoutOverflowLikeCarbon(): void
    {
        $utc = new \DateTimeZone('UTC');

        self::assertSame('2026-02-28 10:00:00', Checkout::addMonthsNoOverflow(new \DateTimeImmutable('2026-01-31 10:00:00', $utc), 1)->format('Y-m-d H:i:s'));
        self::assertSame('2028-02-29 10:00:00', Checkout::addMonthsNoOverflow(new \DateTimeImmutable('2028-01-31 10:00:00', $utc), 1)->format('Y-m-d H:i:s'));
        self::assertSame('2027-01-15 10:00:00', Checkout::addMonthsNoOverflow(new \DateTimeImmutable('2026-01-15 10:00:00', $utc), 12)->format('Y-m-d H:i:s'));
        self::assertSame('2027-02-28 10:00:00', Checkout::addMonthsNoOverflow(new \DateTimeImmutable('2026-11-30 10:00:00', $utc), 3)->format('Y-m-d H:i:s'));
    }

    public function testTestPaymentsAreImpossibleInProduction(): void
    {
        $prod = new PaymentGatewayFactory('test', 'https://food-guru.ru', 'prod');
        self::assertNull($prod->gateway());
        self::assertFalse($prod->testMode());

        // Имя окружения staging, опечатка — тоже нельзя: разрешены только dev и test.
        foreach (['production', 'staging', 'Prod', ''] as $env) {
            self::assertNull((new PaymentGatewayFactory('test', 'https://food-guru.ru', $env))->gateway(), "окружение «{$env}»");
        }

        $dev = new PaymentGatewayFactory('test', 'http://localhost:4200', 'dev');
        self::assertSame('test', $dev->gateway()?->code());
        self::assertTrue($dev->testMode());

        self::assertNull((new PaymentGatewayFactory('', 'x', 'dev'))->gateway());
    }
}
