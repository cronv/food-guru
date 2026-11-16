<?php

declare(strict_types=1);

namespace App\Controller\Content;

use App\Catalog\PublishedRecipes;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Всё публичное для карты сайта. Сам sitemap.xml
 * собирает SSR-сервер сайта: адреса должны быть на домене сайта, а не API.
 * Здесь — пути и даты изменения. Только то, что видно гостю.
 */
final class SeoController
{
    /** Таблица => [префикс пути, приоритет, условие «опубликовано»]. Порядок — как в прежней карте сайта. */
    private const SECTIONS = [
        ['recipes', '/recipes/', 0.8, PublishedRecipes::SQL],
        ['categories', '/categories/', 0.6, '1 = 1'],
        ['collections', '/collections/', 0.6, 'r.is_published = 1'],
        ['books', '/books/', 0.6, 'r.is_published = 1'],
        ['products', '/shop/', 0.6, 'r.is_published = 1'],
        ['contests', '/contests/', 0.4, PublishedRecipes::SQL],
        ['academy_sessions', '/academy/', 0.4, PublishedRecipes::SQL],
        ['journal_issues', '/journal/', 0.4, PublishedRecipes::SQL],
    ];

    private const PAGES = ['/recipes' => 0.9, '/reels' => 0.6, '/collections' => 0.7, '/books' => 0.7, '/shop' => 0.7,
        '/club' => 0.6, '/academy' => 0.6, '/contests' => 0.5, '/journal' => 0.5, '/rating' => 0.4, '/help' => 0.3];

    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/seo/sitemap', name: 'seo_sitemap', methods: ['GET'])]
    public function sitemap(): JsonResponse
    {
        // Карту запрашивают роботы, а не люди: час кэша не даст им нагружать базу.
        $urls = $this->cache->get('seo.sitemap', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            $items = [self::url('/', null, 1.0, 'daily')];
            foreach (self::PAGES as $path => $priority) {
                $items[] = self::url($path, null, $priority, 'daily');
            }

            $now = PublishedRecipes::now($this->clock->now());
            foreach (self::SECTIONS as [$table, $prefix, $priority, $where]) {
                $rows = $this->db->fetchAllAssociative(
                    "SELECT r.slug, r.updated_at FROM {$table} r WHERE {$where} ORDER BY r.id ASC",
                    str_contains($where, ':now') ? ['now' => $now] : [],
                );
                foreach ($rows as $row) {
                    $items[] = self::url($prefix.$row['slug'], $row['updated_at'], $priority, 'weekly');
                }
            }

            return $items;
        });

        return new JsonResponse(['data' => $urls]);
    }

    /** @return array{path: string, lastmod: string|null, priority: float, changefreq: string} */
    private static function url(string $path, mixed $lastmod, float $priority, string $freq): array
    {
        return [
            'path' => $path,
            'lastmod' => null === $lastmod ? null : substr((string) $lastmod, 0, 10),
            'priority' => $priority,
            'changefreq' => $freq,
        ];
    }
}
