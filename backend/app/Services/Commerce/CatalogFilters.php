<?php

namespace App\Services\Commerce;

use Illuminate\Database\Eloquent\Builder;

/** Общие кирпичи фильтров магазина и книг: поиск, списки значений, диапазон цены. */
class CatalogFilters
{
    /**
     * Поиск по названию; % и _ в запросе — буквально, а не шаблон.
     * Регистр не важен за счёт collation MySQL (utf8mb4_unicode_ci), в том числе
     * для кириллицы. LOWER() не используем: он отключил бы индекс.
     */
    public static function search(Builder $query, ?string $q, string $column = 'title'): void
    {
        $q = trim((string) $q);
        if ($q === '') {
            return;
        }

        $query->where($column, 'like', '%'.addcslashes($q, '%_\\').'%');
    }

    /** Несколько значений одного поля — «или»; разные поля между собой — «и». */
    public static function in(Builder $query, string $column, ?array $values): void
    {
        $values = array_values(array_filter((array) $values, fn ($v) => $v !== null && $v !== ''));
        if ($values) {
            $query->whereIn($column, $values);
        }
    }

    /** Цена в копейках, границы включительно. */
    public static function price(Builder $query, ?int $min, ?int $max, string $column = 'price'): void
    {
        if ($min !== null) {
            $query->where($column, '>=', $min);
        }
        if ($max !== null) {
            $query->where($column, '<=', $max);
        }
    }

    /** Варианты для фильтра — только те, что реально встречаются в опубликованном. */
    public static function facet(Builder $base, string $column): array
    {
        return (clone $base)->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)->values()->all();
    }

    public static function priceRange(Builder $base, string $column = 'price'): array
    {
        return [
            'min' => (int) (clone $base)->min($column),
            'max' => (int) (clone $base)->max($column),
        ];
    }
}
