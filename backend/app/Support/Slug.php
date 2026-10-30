<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Адрес записи из заголовка. Кириллица переводится в латиницу,
 * совпадения разводятся числовым хвостом.
 */
class Slug
{
    /**
     * @param  class-string<Model>  $model
     * @param  int|null  $ignoreId  id записи, которую правим: её собственный адрес не считается занятым
     */
    public static function unique(string $model, string $source, string $fallback, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::ascii($source)) ?: $fallback;
        $slug = $base;
        $i = 2;

        while (self::taken($model, $slug, $ignoreId)) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private static function taken(string $model, string $slug, ?int $ignoreId): bool
    {
        return $model::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
