<?php

declare(strict_types=1);

namespace App\Security\Attribute;

/**
 * Свой лимит маршрута поверх общего
 * (30 запросов за 60 минут на человека). Заголовки X-RateLimit-* в ответе — этого лимита.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RouteThrottle
{
    public function __construct(
        public readonly int $limit,
        public readonly int $minutes,
    ) {
    }
}
