<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\ApiTestCase;

/** Те же правила, что у SSR-маршрутов (SsrRateLimitTest). */
final class RateLimitTest extends ApiTestCase
{
    /** @param array<string, string> $headers */
    private function hit(array $headers = []): int
    {
        $this->json('GET', '/api/v1/_probe/public', $headers);

        return $this->client->getResponse()->getStatusCode();
    }

    public function testSixtyPerIpThenTooManyAttempts429(): void
    {
        for ($i = 1; $i <= 60; ++$i) {
            self::assertSame(200, $this->hit());
        }

        $body = $this->json('GET', '/api/v1/_probe/public');

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['message' => 'Too Many Attempts.'], $body);
        self::assertResponseHasHeader('Retry-After');
        self::assertResponseHeaderSame('X-RateLimit-Remaining', '0');
    }

    public function testSsrWithKeyIsLimitedPerVisitor(): void
    {
        for ($i = 1; $i <= 100; ++$i) {
            self::assertSame(200, $this->hit(['HTTP_X_SSR_KEY' => 'test-ssr-key', 'HTTP_X_CLIENT_IP' => "10.0.0.$i"]));
        }
    }

    public function testWrongSsrKeyDoesNotBypassLimit(): void
    {
        for ($i = 1; $i <= 60; ++$i) {
            $this->hit(['HTTP_X_SSR_KEY' => 'guess', 'HTTP_X_CLIENT_IP' => "10.0.1.$i"]);
        }

        self::assertSame(429, $this->hit(['HTTP_X_SSR_KEY' => 'guess', 'HTTP_X_CLIENT_IP' => '10.0.1.99']));
    }
}
