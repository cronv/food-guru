<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Поиск по названию и лиду на MySQL:
 * полнотекстовый индекс (title, lead), естественный режим. Пустой запрос не фильтрует.
 */
final class FullText
{
    public static function apply(QueryBuilder $qb, string $alias, ?string $term): QueryBuilder
    {
        if (null === $term || '' === trim($term)) {
            return $qb;
        }

        return $qb->andWhere("MATCH ({$alias}.title, {$alias}.lead) AGAINST (:search IN NATURAL LANGUAGE MODE)")
            ->setParameter('search', $term);
    }
}
