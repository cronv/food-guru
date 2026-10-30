<?php

namespace App\Http\Resources;

use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Полная карточка рецепта.
 *
 * @mixin Recipe
 */
class RecipeResource extends JsonResource
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
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'og_description' => $this->og_description ?: $this->lead,
            'nutrition' => [
                'calories' => $this->calories,
                'protein' => $this->protein,
                'fat' => $this->fat,
                'carbs' => $this->carbs,
            ],
            'is_vegetarian' => $this->is_vegetarian,
            'is_gluten_free' => $this->is_gluten_free,
            'moderation_note' => $this->when($this->moderation_note !== null, $this->moderation_note),

            'cover' => $this->cover ? new MediaResource($this->cover) : null,
            'reel_url' => $this->reelUrl(),
            'views_count' => (int) $this->views_count,
            'likes_count' => (int) $this->likes_count,
            'dislikes_count' => (int) $this->dislikes_count,
            // Только для вошедшего: у гостя поля нет совсем, а не null.
            'my_reaction' => $this->when($request->user() !== null, fn () => $this->my_reaction ?? null),
            'gallery' => MediaResource::collection($this->whenLoaded('gallery')),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'slug' => $this->category->slug,
                'title' => $this->category->title,
            ] : null),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'role_label' => $this->author->role->label(),
            ]),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'title' => $t->title,
            ])),

            // Количество указано на одну порцию: фронт умножает на выбранное число.
            'ingredients' => $this->whenLoaded('ingredients', fn () => $this->ingredients->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'amount' => $i->amount,
                'unit' => $i->unit,
            ])),
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($s) => [
                'id' => $s->id,
                'position' => $s->position,
                'body' => $s->body,
                'hint' => $s->hint,
                'tip' => $s->tip,
                'substitution' => $s->substitution,
                'chef_secret' => $s->chef_secret,
                'media' => $s->media ? new MediaResource($s->media) : null,
            ])),

            'reviews_count' => $this->whenCounted('reviews'),
            'saves_count' => $this->when(isset($this->saves_count), fn () => (int) $this->saves_count),
            'rating' => $this->when(isset($this->reviews_avg_rating),
                fn () => $this->reviews_avg_rating ? round((float) $this->reviews_avg_rating, 1) : null),
            'is_favorite' => $this->when(isset($this->is_favorite), fn () => (bool) $this->is_favorite),
            'in_cookbook' => $this->when(isset($this->in_cookbook), fn () => (bool) $this->in_cookbook),
        ];
    }
}
