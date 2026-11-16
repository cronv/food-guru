<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Clock\ClockInterface;

/**
 * Выборки рецептов для публичной части (чтение, DBAL). SQL повторяет прежнюю выборку:
 * recipes.* плюс подзапросы для счётчиков. Это важно не только
 * для полей: при равных значениях сортировки MySQL упорядочивает строки по плану
 * запроса, и другой по форме запрос дал бы другой порядок строк.
 */
final class RecipeQuery
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** select recipes.* from recipes where <опубликован>. */
    public function published(): QueryBuilder
    {
        return $this->db->createQueryBuilder()
            ->select('r.*')
            ->from('recipes', 'r')
            ->where(PublishedRecipes::SQL)
            ->setParameter('now', PublishedRecipes::now($this->clock->now()));
    }

    /** withCount('reviews') — все отзывы, без фильтра по статусу. */
    public function withReviewsCount(QueryBuilder $qb): QueryBuilder
    {
        return $qb->addSelect('(SELECT COUNT(*) FROM reviews rv WHERE r.id = rv.recipe_id) AS reviews_count');
    }

    /** withAvg('reviews', 'rating'). */
    public function withReviewsAvg(QueryBuilder $qb): QueryBuilder
    {
        return $qb->addSelect('(SELECT AVG(rv.rating) FROM reviews rv WHERE r.id = rv.recipe_id) AS reviews_avg_rating');
    }

    /** withCount('savedBy as saves_count') — сколько раз рецепт сохранили в рецептосохранялку. */
    public function withSavesCount(QueryBuilder $qb): QueryBuilder
    {
        return $qb->addSelect('(SELECT COUNT(*) FROM users su INNER JOIN cookbook_recipes cr ON su.id = cr.user_id WHERE r.id = cr.recipe_id) AS saves_count');
    }

    /**
     * Страница выборки: строки и общее число — как paginate() (два запроса).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(QueryBuilder $qb, int $page, int $perPage): array
    {
        $count = clone $qb;
        $count->select('COUNT(*)')->resetOrderBy();
        $total = (int) $count->executeQuery()->fetchOne();

        $rows = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->executeQuery()->fetchAllAssociative();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * По id — для адресов вида /recipes/{recipe:id}/…: рецепт ищется
     * в любом статусе, а не опубликованный даёт 404. Итог тот же.
     *
     * @return array<string, mixed>|null
     */
    public function findPublishedById(int $id): ?array
    {
        $row = $this->published()->andWhere('r.id = :id')->setParameter('id', $id)->setMaxResults(1)->executeQuery()->fetchAssociative();

        return false === $row ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findPublishedBySlug(string $slug, bool $full = false): ?array
    {
        $qb = $this->published()->andWhere('r.slug = :slug')->setParameter('slug', $slug);
        if ($full) {
            $this->withReviewsCount($qb);
            $this->withSavesCount($qb);
            $this->withReviewsAvg($qb);
        }

        $row = $qb->setMaxResults(1)->executeQuery()->fetchAssociative();

        return false === $row ? null : $row;
    }

    /**
     * Похожие: та же категория или общие теги, свежие первыми, три штуки.
     *
     * @param array<string, mixed> $recipe
     *
     * @return list<array<string, mixed>>
     */
    public function related(array $recipe): array
    {
        $tagIds = $this->db->fetchFirstColumn('SELECT tag_id FROM recipe_tag WHERE recipe_id = ?', [$recipe['id']]);

        $qb = $this->published()->andWhere('r.id != :id')->setParameter('id', $recipe['id']);
        $sameCategory = null === $recipe['category_id'] ? 'r.category_id IS NULL' : 'r.category_id = :category';
        $sharedTags = [] === $tagIds
            ? '1 = 0'
            : 'EXISTS (SELECT 1 FROM tags t INNER JOIN recipe_tag rt ON t.id = rt.tag_id WHERE r.id = rt.recipe_id AND t.id IN (:tags))';

        $qb->andWhere("({$sameCategory} OR {$sharedTags})")
            ->orderBy('r.published_at', 'DESC')
            ->setMaxResults(3);

        if (null !== $recipe['category_id']) {
            $qb->setParameter('category', $recipe['category_id']);
        }
        if ([] !== $tagIds) {
            $qb->setParameter('tags', $tagIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }
}
