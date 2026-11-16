<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Media\MediaUrls;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Отзыв в JSON с загруженными автором и фото (без поля recipe). */
final class ReviewPresenter
{
    public function __construct(
        private readonly Connection $db,
        private readonly MediaUrls $media,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows строки reviews
     *
     * @return list<array<string, mixed>>
     */
    public function present(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $authors = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, name FROM users WHERE id IN (?)', [array_column($rows, 'user_id')], [ArrayParameterType::INTEGER]) as $a) {
            $authors[(int) $a['id']] = $a;
        }

        $photos = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT m.*, rm.review_id AS pivot_review_id FROM media m INNER JOIN review_media rm ON m.id = rm.media_id
              WHERE rm.review_id IN (?) ORDER BY rm.position ASC',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $m) {
            $photos[(int) $m['pivot_review_id']][] = $this->media->present($m);
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'rating' => (int) $r['rating'],
            'body' => $r['body'],
            'status' => $r['status'],
            'created_at' => RecipePresenter::iso($r['created_at']),
            'author' => ['id' => (int) $authors[(int) $r['user_id']]['id'], 'name' => $authors[(int) $r['user_id']]['name']],
            'media' => $photos[(int) $r['id']] ?? [],
        ], $rows);
    }
}
