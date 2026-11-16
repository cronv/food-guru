<?php

declare(strict_types=1);

namespace App\Repository;

use App\Catalog\PublishedRecipes;
use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Category> */
final class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Верхний уровень с подкатегориями — одним запросом.
     *
     * @return list<Category>
     */
    public function findRootsWithChildren(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'ch')->addSelect('ch')
            ->where('c.parent IS NULL')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('ch.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Число опубликованных рецептов по категориям.
     *
     * @return array<int, int> id категории => число рецептов
     */
    public function publishedRecipeCounts(\DateTimeImmutable $now): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT r.category_id, COUNT(*) FROM recipes r WHERE r.category_id IS NOT NULL AND '
            .PublishedRecipes::SQL.' GROUP BY r.category_id',
            ['now' => PublishedRecipes::now($now)],
        );

        $counts = [];
        foreach ($rows as $categoryId => $count) {
            $counts[(int) $categoryId] = (int) $count;
        }

        return $counts;
    }
}
