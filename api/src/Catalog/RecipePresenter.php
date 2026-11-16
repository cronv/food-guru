<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enum\UserRole;
use App\Media\MediaUrls;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Рецепт в JSON: карточка и полная версия, те же поля,
 * в том же порядке. Связанные данные (обложки, категории, теги, авторы) грузятся
 * пачкой на всю страницу, а не по запросу на рецепт.
 *
 * Поле не выводится, если связь не загружена (whenLoaded) или счётчик не
 * посчитан (whenCounted). Что не запрошено, того в ответе нет.
 */
final class RecipePresenter
{
    public const COVER = 'cover';
    public const CATEGORY = 'category';
    public const TAGS = 'tags';
    public const AUTHOR = 'author';

    public function __construct(
        private readonly Connection $db,
        private readonly MediaUrls $media,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows строки recipes (+ reviews_count, saves_count, reviews_avg_rating)
     * @param list<string>               $with какие связи вывести
     *
     * @return list<array<string, mixed>>
     */
    public function cards(array $rows, array $with = [self::COVER, self::CATEGORY]): array
    {
        if ([] === $rows) {
            return [];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $covers = \in_array(self::COVER, $with, true) ? $this->mediaByIds(array_column($rows, 'cover_media_id')) : [];
        $categories = \in_array(self::CATEGORY, $with, true) ? $this->categoriesByIds(array_column($rows, 'category_id')) : [];
        $tags = \in_array(self::TAGS, $with, true) ? $this->tagsByRecipe($ids) : [];
        $authors = \in_array(self::AUTHOR, $with, true) ? $this->authorsByIds(array_column($rows, 'author_id')) : [];

        return array_map(function (array $r) use ($with, $covers, $categories, $tags, $authors): array {
            $card = [
                'id' => (int) $r['id'],
                'slug' => $r['slug'],
                'title' => $r['title'],
                'lead' => $r['lead'],
                'time_minutes' => self::int($r['time_minutes']),
                'servings' => (int) $r['servings'],
                'difficulty' => $r['difficulty'],
                'status' => $r['status'],
                'is_vegetarian' => (bool) $r['is_vegetarian'],
                'is_gluten_free' => (bool) $r['is_gluten_free'],
                'calories' => self::int($r['calories']),
                'published_at' => self::iso($r['published_at']),
                'reel_url' => null === $r['reel_path'] ? null : $this->media->url((string) $r['reel_path']),
            ];

            if (\in_array(self::COVER, $with, true)) {
                $card['cover'] = $covers[(int) $r['cover_media_id']] ?? null;
            }
            if (\in_array(self::CATEGORY, $with, true)) {
                $category = $categories[(int) $r['category_id']] ?? null;
                $card['category'] = null === $category ? null : ['slug' => $category['slug'], 'title' => $category['title']];
            }
            if (\in_array(self::AUTHOR, $with, true)) {
                $author = $authors[(int) $r['author_id']];
                $card['author'] = ['id' => (int) $author['id'], 'name' => $author['name']];
            }
            if (\in_array(self::TAGS, $with, true)) {
                $card['tags'] = array_map(static fn (array $t): array => ['slug' => $t['slug'], 'title' => $t['title']], $tags[(int) $r['id']] ?? []);
            }

            return $card + $this->counters($r, withLikes: true);
        }, $rows);
    }

    /**
     * Полная карточка рецепта.
     *
     * @param array<string, mixed>      $r
     * @param array<string, mixed>|null $personal для вошедшего: my_reaction и, если посчитаны, is_favorite, in_cookbook.
     *                                            Чего нет в массиве — того нет и в ответе (проверка isset())
     *
     * @return array<string, mixed>
     */
    public function full(array $r, ?array $personal, bool $withAuthor = true): array
    {
        $id = (int) $r['id'];
        $cover = $this->mediaByIds([$r['cover_media_id']])[(int) $r['cover_media_id']] ?? null;
        $category = $this->categoriesByIds([$r['category_id']])[(int) $r['category_id']] ?? null;

        $out = [
            'id' => $id,
            'slug' => $r['slug'],
            'title' => $r['title'],
            'lead' => $r['lead'],
            'time_minutes' => self::int($r['time_minutes']),
            'servings' => (int) $r['servings'],
            'difficulty' => $r['difficulty'],
            'status' => $r['status'],
            'published_at' => self::iso($r['published_at']),
            'updated_at' => self::iso($r['updated_at']),
            'og_description' => ($r['og_description'] ?? '') !== '' ? $r['og_description'] : $r['lead'],
            'nutrition' => [
                'calories' => self::int($r['calories']),
                'protein' => self::int($r['protein']),
                'fat' => self::int($r['fat']),
                'carbs' => self::int($r['carbs']),
            ],
            'is_vegetarian' => (bool) $r['is_vegetarian'],
            'is_gluten_free' => (bool) $r['is_gluten_free'],
        ];

        if (null !== $r['moderation_note']) {
            $out['moderation_note'] = $r['moderation_note'];
        }

        $out += [
            'cover' => $cover,
            'reel_url' => null === $r['reel_path'] ? null : $this->media->url((string) $r['reel_path']),
            'views_count' => (int) $r['views_count'],
            'likes_count' => (int) $r['likes_count'],
            'dislikes_count' => (int) $r['dislikes_count'],
        ];

        // У гостя поля нет совсем, у вошедшего — значение или null.
        if (null !== $personal) {
            $out['my_reaction'] = $personal['my_reaction'] ?? null;
        }

        $out['gallery'] = $this->gallery($id);
        $out['category'] = null === $category ? null : ['id' => (int) $category['id'], 'slug' => $category['slug'], 'title' => $category['title']];
        if ($withAuthor) {
            $author = $this->authorsByIds([$r['author_id']])[(int) $r['author_id']];
            $out['author'] = [
                'id' => (int) $author['id'],
                'name' => $author['name'],
                'role_label' => UserRole::from((string) $author['role'])->label(),
            ];
        }
        $out += [
            'tags' => array_map(static fn (array $t): array => ['id' => (int) $t['id'], 'slug' => $t['slug'], 'title' => $t['title']], $this->tagsByRecipe([$id])[$id] ?? []),
            'ingredients' => $this->ingredients($id),
            'steps' => $this->steps($id),
        ];

        $out += $this->counters($r, withLikes: false);

        foreach (['is_favorite', 'in_cookbook'] as $flag) {
            if (null !== $personal && \array_key_exists($flag, $personal)) {
                $out[$flag] = $personal[$flag];
            }
        }

        return $out;
    }

    /**
     * reviews_count, saves_count, likes_count, rating — что посчитано в выборке.
     *
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function counters(array $r, bool $withLikes): array
    {
        $out = [];
        if (\array_key_exists('reviews_count', $r)) {
            $out['reviews_count'] = (int) $r['reviews_count'];
        }
        if (\array_key_exists('saves_count', $r)) {
            $out['saves_count'] = (int) $r['saves_count'];
        }
        if ($withLikes) {
            $out['likes_count'] = (int) $r['likes_count'];
        }
        // Нет отзывов — нет и поля.
        if (isset($r['reviews_avg_rating'])) {
            $avg = (float) $r['reviews_avg_rating'];
            $out['rating'] = 0.0 !== $avg ? round($avg, 1) : null;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function gallery(int $recipeId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT m.*, rm.caption AS pivot_caption FROM media m INNER JOIN recipe_media rm ON m.id = rm.media_id
              WHERE rm.recipe_id = ? ORDER BY rm.position ASC',
            [$recipeId],
        );

        return array_map(fn (array $m): array => $this->media->present($m) + ['caption' => $m['pivot_caption']], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function ingredients(int $recipeId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, name, amount, unit FROM recipe_ingredients WHERE recipe_id = ? ORDER BY position ASC', [$recipeId]);

        // amount приведён к float: «50.000» из DECIMAL становится 50.0.
        return array_map(static fn (array $i): array => [
            'id' => (int) $i['id'],
            'name' => $i['name'],
            'amount' => null === $i['amount'] ? null : (float) $i['amount'],
            'unit' => $i['unit'],
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function steps(int $recipeId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM recipe_steps WHERE recipe_id = ? ORDER BY position ASC', [$recipeId]);
        $media = $this->mediaByIds(array_column($rows, 'media_id'));

        return array_map(static fn (array $s): array => [
            'id' => (int) $s['id'],
            'position' => (int) $s['position'],
            'body' => $s['body'],
            'hint' => $s['hint'],
            'tip' => $s['tip'],
            'substitution' => $s['substitution'],
            'chef_secret' => $s['chef_secret'],
            'media' => $media[(int) $s['media_id']] ?? null,
        ], $rows);
    }

    /**
     * @param list<mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function mediaByIds(array $ids): array
    {
        $out = [];
        foreach ($this->byIds('media', $ids) as $id => $row) {
            $out[$id] = $this->media->present($row);
        }

        return $out;
    }

    /**
     * @param list<mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoriesByIds(array $ids): array
    {
        return $this->byIds('categories', $ids);
    }

    /**
     * @param list<mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function authorsByIds(array $ids): array
    {
        return $this->byIds('users', $ids, 'id, name, role');
    }

    /**
     * Теги рецептов в порядке связи — как belongsToMany без сортировки.
     *
     * @param list<int> $recipeIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function tagsByRecipe(array $recipeIds): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT t.*, rt.recipe_id AS pivot_recipe_id FROM tags t INNER JOIN recipe_tag rt ON t.id = rt.tag_id WHERE rt.recipe_id IN (?)',
            [$recipeIds],
            [ArrayParameterType::INTEGER],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['pivot_recipe_id']][] = $row;
        }

        return $out;
    }

    /**
     * @param list<mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function byIds(string $table, array $ids, string $columns = '*'): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ([] === $ids) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative("SELECT {$columns} FROM {$table} WHERE id IN (?)", [$ids], [ArrayParameterType::INTEGER]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    public static function iso(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        // Carbon::toIso8601String(): 2026-10-26T09:34:08+00:00 — в базе время UTC.
        return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
    }

    private static function int(mixed $value): ?int
    {
        return null === $value ? null : (int) $value;
    }
}
