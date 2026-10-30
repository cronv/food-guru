<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RecipeCardResource;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use App\Models\RecipeReaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecipeController
{
    /** Каталог с фильтрами. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            // Каждый тег — отдельный подзапрос; без потолка тысяча тегов в адресе кладёт базу.
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:60'],
            'max_time' => ['nullable', 'integer', 'min:5', 'max:600'],
            'difficulty' => ['nullable', 'array'],
            'difficulty.*' => ['string', 'in:easy,medium,hard'],
            'diet' => ['nullable', 'array'],
            'diet.*' => ['string', 'in:vegetarian,gluten_free'],
            'sort' => ['nullable', 'in:popular,new,fast'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $query = Recipe::published()
            ->with(['cover', 'category', 'tags'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating');

        if (! empty($data['q'])) {
            $query->search($data['q']);
        }

        if (! empty($data['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $data['category']));
        }

        if (! empty($data['tags'])) {
            // Рецепт должен нести все перечисленные теги, а не любой из них.
            foreach ($data['tags'] as $tag) {
                $query->whereHas('tags', fn ($q) => $q->where('slug', $tag));
            }
        }

        if (! empty($data['max_time'])) {
            $query->where('time_minutes', '<=', $data['max_time']);
        }

        if (! empty($data['difficulty'])) {
            $query->whereIn('difficulty', $data['difficulty']);
        }

        foreach ($data['diet'] ?? [] as $diet) {
            $query->where($diet === 'vegetarian' ? 'is_vegetarian' : 'is_gluten_free', true);
        }

        match ($data['sort'] ?? 'popular') {
            'new' => $query->orderByDesc('published_at'),
            'fast' => $query->orderBy('time_minutes'),
            default => $query->orderByDesc('reviews_count')->orderByDesc('published_at'),
        };

        return RecipeCardResource::collection(
            $query->paginate($data['per_page'] ?? 12)->withQueryString()
        );
    }

    public function show(Request $request, string $slug): RecipeResource
    {
        $recipe = Recipe::published()
            ->where('slug', $slug)
            ->with(['cover', 'gallery', 'category', 'author', 'tags', 'ingredients', 'steps.media'])
            ->withCount(['reviews', 'savedBy as saves_count'])
            ->withAvg('reviews', 'rating')
            ->firstOrFail();

        if ($user = $request->user()) {
            $recipe->is_favorite = $user->favorites()
                ->where('recipes.id', $recipe->id)
                ->exists();

            $recipe->in_cookbook = $user->cookbookRecipes()
                ->where('recipes.id', $recipe->id)
                ->exists();

            $recipe->my_reaction = RecipeReaction::where('recipe_id', $recipe->id)
                ->where('user_id', $user->id)
                ->value('reaction');
        }

        return new RecipeResource($recipe);
    }

    /** Похожие: сначала из той же категории, затем по общим тегам. */
    public function related(string $slug): AnonymousResourceCollection
    {
        $recipe = Recipe::published()->where('slug', $slug)->firstOrFail();

        $related = Recipe::published()
            ->where('id', '!=', $recipe->id)
            ->where(function ($q) use ($recipe) {
                $q->where('category_id', $recipe->category_id)
                    ->orWhereHas('tags', fn ($t) => $t->whereIn('tags.id', $recipe->tags()->pluck('tags.id')));
            })
            ->with(['cover', 'category'])
            ->orderByDesc('published_at')
            ->take(3)
            ->get();

        return RecipeCardResource::collection($related);
    }
}
