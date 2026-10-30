<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Поиск по названию и лиду.
 *
 * На MySQL работает полнотекстовый индекс. На остальных драйверах его нет:
 * SQLite не умеет FULLTEXT вовсе, а тесты гоняются именно на нём, в памяти,
 * без поднятого сервера базы. Поэтому там тот же поиск идёт через LIKE —
 * медленнее, но на тестовых объёмах это незаметно, зато набор тестов
 * запускается одной командой и без окружения.
 */
trait Searchable
{
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $columns = $this->searchableColumns();

        if ($this->getConnection()->getDriverName() === 'mysql') {
            return $query->whereFullText($columns, $term);
        }

        return $query->where(function (Builder $q) use ($columns, $term) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    /** @return array<int, string> */
    protected function searchableColumns(): array
    {
        return ['title', 'lead'];
    }
}
