<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enum\RatingLevel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Clock\ClockInterface;

/**
 * Подборки для главной — прежний сервис HomeFeed. Каждая отвечает
 * на свой вопрос, поэтому и сортировка своя:
 *  - популярное за неделю — что делают с рецептом прямо сейчас;
 *  - лучшие — как его оценили, а не сколько раз открыли;
 *  - рейтинг рецептосохранялки — сколько людей сохранили его себе.
 */
final class HomeFeed
{
    public function __construct(
        private readonly RecipeQuery $recipes,
        private readonly RecipePresenter $presenter,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Каталожная выборка: опубликованные, с тем, что нужно карточке. */
    private function cards(): QueryBuilder
    {
        $qb = $this->recipes->published();
        $this->recipes->withSavesCount($qb);
        $this->recipes->withReviewsCount($qb);

        return $this->recipes->withReviewsAvg($qb);
    }

    /**
     * Популярное на этой неделе: сохранения, лайки и отзывы за последние 7 дней.
     * Сохранение весит больше — его делают, когда собираются готовить.
     * Если за неделю тихо, порядок держат накопленные лайки и просмотры.
     *
     * @return list<array<string, mixed>>
     */
    public function popularThisWeek(int $limit = 8): array
    {
        $qb = $this->cards()
            ->addSelect('(SELECT COUNT(*) FROM users wu INNER JOIN cookbook_recipes wc ON wu.id = wc.user_id WHERE r.id = wc.recipe_id AND wc.created_at >= :since) AS week_saves')
            ->addSelect("(SELECT COUNT(*) FROM recipe_reactions wr WHERE r.id = wr.recipe_id AND wr.reaction = 'like' AND wr.created_at >= :since) AS week_likes")
            ->addSelect('(SELECT COUNT(*) FROM reviews wv WHERE r.id = wv.recipe_id AND wv.created_at >= :since) AS week_reviews')
            ->setParameter('since', PublishedRecipes::now($this->clock->now()->modify('-7 days')))
            ->orderBy('(week_saves * 3 + week_likes * 2 + week_reviews * 2)', 'DESC')
            ->addOrderBy('r.likes_count', 'DESC')
            ->addOrderBy('r.views_count', 'DESC')
            ->addOrderBy('r.published_at', 'DESC')
            ->setMaxResults($limit);

        return $this->presenter->cards($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Лучшие: доля лайков со сглаживанием (+1 лайк и +1 дизлайк к каждому).
     * Без сглаживания рецепт с одним лайком (100%) обошёл бы рецепт с 95 из 100.
     *
     * @return list<array<string, mixed>>
     */
    public function best(int $limit = 6): array
    {
        $qb = $this->cards()
            ->orderBy('(r.likes_count + 1.0) / (r.likes_count + r.dislikes_count + 2.0)', 'DESC')
            ->addOrderBy('r.likes_count', 'DESC')
            ->addOrderBy('r.published_at', 'DESC')
            ->setMaxResults($limit);

        return $this->presenter->cards($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Рейтинг по добавлению в рецептосохранялку. Ни разу не сохранённые сюда не попадают.
     *
     * @return list<array<string, mixed>>
     */
    public function mostSaved(int $limit = 6): array
    {
        $qb = $this->cards()
            ->andWhere('EXISTS (SELECT 1 FROM users eu INNER JOIN cookbook_recipes ec ON eu.id = ec.user_id WHERE r.id = ec.recipe_id)')
            ->orderBy('saves_count', 'DESC')
            ->addOrderBy('r.published_at', 'DESC')
            ->setMaxResults($limit);

        return $this->presenter->cards($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Популярные подборки: по сумме сохранений их рецептов. Обложка — своя
     * у подборки, а если её нет — обложка первого рецепта в ней.
     *
     * @return list<array<string, mixed>>
     */
    public function popularCollections(int $limit = 6): array
    {
        $now = PublishedRecipes::now($this->clock->now());
        $rows = $this->db->fetchAllAssociative(
            'SELECT (SELECT COUNT(*) FROM collection_recipe ccr INNER JOIN cookbook_recipes ckr ON ckr.recipe_id = ccr.recipe_id
                      WHERE ccr.collection_id = col.id) AS saves_total,
                    col.*,
                    (SELECT COUNT(*) FROM recipes r INNER JOIN collection_recipe pcr ON r.id = pcr.recipe_id
                      WHERE col.id = pcr.collection_id AND '.PublishedRecipes::SQL.') AS recipes_count
               FROM collections col
              WHERE col.is_published = 1
              ORDER BY saves_total DESC, col.id ASC
              LIMIT '.$limit,
            ['now' => $now],
        );

        $out = [];
        foreach ($rows as $c) {
            $mediaId = $c['cover_media_id'] ?? $this->db->fetchOne(
                'SELECT r.cover_media_id FROM recipes r INNER JOIN collection_recipe cr ON cr.recipe_id = r.id
                  WHERE '.PublishedRecipes::SQL.' AND cr.collection_id = :collection AND r.cover_media_id IS NOT NULL
                  ORDER BY cr.position ASC LIMIT 1',
                ['now' => $now, 'collection' => $c['id']],
            );
            $cover = $mediaId ? ($this->presenter->mediaByIds([$mediaId])[(int) $mediaId] ?? null) : null;

            $out[] = [
                'slug' => $c['slug'],
                'title' => $c['title'],
                'lead' => $c['lead'],
                'recipes_count' => (int) $c['recipes_count'],
                'saves_total' => (int) $c['saves_total'],
                'cover' => null === $cover ? null : ['url' => $cover['url'], 'alt' => $cover['alt']],
            ];
        }

        return $out;
    }

    /**
     * Топ кулинаров — те же правила, что у таблицы лидеров: баллы рейтинга.
     * Только читатели: редакция набирает баллы за рабочие публикации и заняла бы весь верх.
     *
     * @return list<array<string, mixed>>
     */
    public function topCooks(int $limit = 6): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.name, u.rating_points, u.rating_level,
                    (SELECT COUNT(*) FROM recipes r WHERE u.id = r.author_id AND '.PublishedRecipes::SQL.') AS recipes_count,
                    (SELECT COUNT(*) FROM reviews rv WHERE u.id = rv.user_id) AS reviews_count
               FROM users u
              WHERE u.role = :role AND u.email_verified_at IS NOT NULL AND u.is_active = 1 AND u.rating_points > 0
              ORDER BY u.rating_points DESC, u.id ASC
              LIMIT '.$limit,
            ['role' => 'user', 'now' => PublishedRecipes::now($this->clock->now())],
        );

        return array_map(static fn (array $u): array => [
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'rating_points' => (int) $u['rating_points'],
            'rating_label' => RatingLevel::from((string) $u['rating_level'])->label(),
            'recipes_count' => (int) $u['recipes_count'],
            'reviews_count' => (int) $u['reviews_count'],
        ], $rows);
    }
}
