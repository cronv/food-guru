<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Что считается опубликованным рецептом:
 * статус published и дата публикации уже наступила. Одно место на весь каталог.
 */
final class PublishedRecipes
{
    /** Условие для рецептов под псевдонимом r; время передаётся параметром :now (UTC). */
    public const SQL = "r.status = 'published' AND r.published_at IS NOT NULL AND r.published_at <= :now";

    public static function now(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
