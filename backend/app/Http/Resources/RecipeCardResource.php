<?php

namespace App\Http\Resources;

use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Короткая форма для каталога и списков — без ингредиентов и шагов.
 *
 * @mixin Recipe
 */
class RecipeCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'lead' => $this->lead,
            'time_minutes' => $this->time_minutes,
            'servings' => $this->servings,
            'difficulty' => $this->difficulty,
            'status' => $this->status->value,
            'is_vegetarian' => $this->is_vegetarian,
            'is_gluten_free' => $this->is_gluten_free,
            'calories' => $this->calories,
            'published_at' => $this->published_at?->toIso8601String(),
            'reel_url' => $this->reelUrl(),
            'cover' => $this->whenLoaded('cover', fn () => $this->cover ? new MediaResource($this->cover) : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'slug' => $this->category->slug,
                'title' => $this->category->title,
            ] : null),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
                'slug' => $t->slug,
                'title' => $t->title,
            ])),
            'reviews_count' => $this->whenCounted('reviews'),
            // Сколько людей сохранили рецепт в рецептосохранялку.
            'saves_count' => $this->when(isset($this->saves_count), fn () => (int) $this->saves_count),
            'likes_count' => (int) $this->likes_count,
            'rating' => $this->when(isset($this->reviews_avg_rating),
                fn () => $this->reviews_avg_rating ? round((float) $this->reviews_avg_rating, 1) : null),
        ];
    }
}
