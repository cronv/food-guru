<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\Tag;
use Illuminate\Support\Str;

/**
 * Запись состава рецепта: ингредиенты, шаги, теги, галерея и адрес.
 * Общая для админки и кабинета читателя — правила не должны расходиться.
 */
class RecipeWriter
{
    /** Ингредиенты, шаги, теги и галерея переписываются целиком — так проще и предсказуемее. */
    public function syncChildren(Recipe $recipe, array $data): void
    {
        if (isset($data['ingredients'])) {
            $recipe->ingredients()->delete();
            foreach ($data['ingredients'] as $i => $row) {
                $recipe->ingredients()->create([
                    'name' => $row['name'],
                    'amount' => $row['amount'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'position' => $i,
                ]);
            }
        }

        if (isset($data['steps'])) {
            $recipe->steps()->delete();
            foreach ($data['steps'] as $i => $row) {
                $recipe->steps()->create([
                    'body' => $row['body'],
                    'hint' => $row['hint'] ?? null,
                    'tip' => $row['tip'] ?? null,
                    'substitution' => $row['substitution'] ?? null,
                    'chef_secret' => $row['chef_secret'] ?? null,
                    'media_id' => $row['media_id'] ?? null,
                    'position' => $i,
                ]);
            }
        }

        if (isset($data['tags'])) {
            $ids = [];
            foreach ($data['tags'] as $title) {
                $slug = Str::slug(Str::ascii($title)) ?: Str::random(8);
                $ids[] = Tag::firstOrCreate(['slug' => $slug], ['title' => $title])->id;
            }
            $recipe->tags()->sync($ids);
        }

        if (isset($data['gallery'])) {
            $sync = [];
            foreach ($data['gallery'] as $i => $row) {
                $sync[$row['media_id']] = ['caption' => $row['caption'] ?? null, 'position' => $i];
            }
            $recipe->gallery()->sync($sync);
        }
    }

    public function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::ascii($source)) ?: 'recipe';
        $slug = $base;
        $i = 2;

        while (Recipe::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
