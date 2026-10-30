<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Лимит API для серверного рендера. SSR ходит в API за всех посетителей с одного IP:
 * без ключа он упёрся бы в общие 60 запросов в минуту, и сайт отдавал бы ошибки.
 */
class SsrRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function hit(array $headers): int
    {
        return $this->withHeaders($headers)->getJson('/api/v1/categories')->status();
    }

    public function test_без_ключа_общий_лимит_на_ip(): void
    {
        config(['food_guru.ssr_key' => 'secret-ssr-key']);

        foreach (range(1, 60) as $i) {
            $this->assertSame(200, $this->hit([]));
        }
        $this->assertSame(429, $this->hit([]));
    }

    public function test_ssr_с_ключом_считается_по_посетителю(): void
    {
        config(['food_guru.ssr_key' => 'secret-ssr-key']);

        // Сотня рендеров для разных посетителей с одного IP сервера — все проходят.
        foreach (range(1, 100) as $i) {
            $this->assertSame(200, $this->hit(['X-SSR-Key' => 'secret-ssr-key', 'X-Client-IP' => "10.0.0.{$i}"]));
        }

        // А один посетитель всё равно ограничен.
        foreach (range(1, 300) as $i) {
            $this->hit(['X-SSR-Key' => 'secret-ssr-key', 'X-Client-IP' => '203.0.113.7']);
        }
        $this->assertSame(429, $this->hit(['X-SSR-Key' => 'secret-ssr-key', 'X-Client-IP' => '203.0.113.7']));
    }

    public function test_чужой_ключ_и_пустой_ключ_не_дают_обойти_лимит(): void
    {
        config(['food_guru.ssr_key' => 'secret-ssr-key']);

        foreach (range(1, 61) as $i) {
            $status = $this->hit(['X-SSR-Key' => 'guess', 'X-Client-IP' => "10.0.1.{$i}"]);
        }
        $this->assertSame(429, $status);
    }

    public function test_без_настроенного_ключа_заголовкам_не_верим(): void
    {
        config(['food_guru.ssr_key' => null]);

        foreach (range(1, 61) as $i) {
            $status = $this->hit(['X-SSR-Key' => '', 'X-Client-IP' => "10.0.2.{$i}"]);
        }
        $this->assertSame(429, $status);
    }
}
