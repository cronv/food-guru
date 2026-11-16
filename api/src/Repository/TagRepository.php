<?php

declare(strict_types=1);

namespace App\Repository;

use App\Catalog\PublishedRecipes;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tag> */
final class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * Теги, у которых есть опубликованные рецепты, — самые частые первыми.
     * При равенстве — по id, чтобы порядок не зависел от плана запроса.
     *
     * @return list<array{slug: string, title: string, recipes_count: int}>
     */
    public function withPublishedRecipeCounts(\DateTimeImmutable $now): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.slug, t.title, COUNT(*) AS recipes_count
               FROM tags t
               JOIN recipe_tag rt ON rt.tag_id = t.id
               JOIN recipes r ON r.id = rt.recipe_id AND '.PublishedRecipes::SQL.'
              GROUP BY t.id, t.slug, t.title
              ORDER BY recipes_count DESC, t.id ASC',
            ['now' => PublishedRecipes::now($now)],
        );

        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'recipes_count' => (int) $row['recipes_count'],
        ], $rows);
    }
}
