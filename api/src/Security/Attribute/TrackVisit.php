<?php

declare(strict_types=1);

namespace App\Security\Attribute;

/**
 * Отмечать ежедневный заход вошедшего пользователя и начислять за него балл —
 * действует на всём личном кабинете.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class TrackVisit
{
}
