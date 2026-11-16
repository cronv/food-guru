<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Общие кирпичи фильтров магазина и книг: поиск, списки значений, диапазон цены.
 * Колонки — из кода, не из запроса.
 */
final class CatalogFilters
{
    private static int $param = 0;

    /**
     * Поиск по названию; % и _ в запросе — буквально, а не шаблон. Регистр не
     * важен за счёт collation utf8mb4_unicode_ci, в том числе для кириллицы.
     */
    public static function search(QueryBuilder $qb, string $alias, ?string $q, string $column = 'title'): void
    {
        $q = trim((string) $q);
        if ('' === $q) {
            return;
        }

        $name = 'search'.++self::$param;
        $qb->andWhere("{$alias}.{$column} LIKE :{$name}")->setParameter($name, '%'.addcslashes($q, '%_\\').'%');
    }

    /**
     * Несколько значений одного поля — «или»; разные поля между собой — «и».
     *
     * @param array<mixed>|null $values
     */
    public static function in(QueryBuilder $qb, string $alias, string $column, ?array $values): void
    {
        $values = array_values(array_filter((array) $values, static fn ($v): bool => null !== $v && '' !== $v));
        if ([] === $values) {
            return;
        }

        $name = 'in'.++self::$param;
        $qb->andWhere("{$alias}.{$column} IN (:{$name})")->setParameter($name, $values, ArrayParameterType::STRING);
    }

    /** Цена в копейках, границы включительно. */
    public static function price(QueryBuilder $qb, string $alias, mixed $min, mixed $max): void
    {
        if (null !== $min) {
            $qb->andWhere("{$alias}.price >= :price_min")->setParameter('price_min', (int) $min);
        }
        if (null !== $max) {
            $qb->andWhere("{$alias}.price <= :price_max")->setParameter('price_max', (int) $max);
        }
    }

    /**
     * Варианты для фильтра — только те, что реально встречаются в опубликованном.
     *
     * @return list<string>
     */
    public static function facet(QueryBuilder $base, string $alias, string $column): array
    {
        return array_map('strval', (clone $base)
            ->select("DISTINCT {$alias}.{$column}")
            ->andWhere("{$alias}.{$column} IS NOT NULL AND {$alias}.{$column} != ''")
            ->orderBy("{$alias}.{$column}", 'ASC')
            ->executeQuery()->fetchFirstColumn());
    }

    /** @return array{min: int, max: int} */
    public static function priceRange(QueryBuilder $base, string $alias): array
    {
        $row = (clone $base)->select("MIN({$alias}.price) AS lo, MAX({$alias}.price) AS hi")->executeQuery()->fetchAssociative();

        return ['min' => (int) ($row['lo'] ?? 0), 'max' => (int) ($row['hi'] ?? 0)];
    }
}
