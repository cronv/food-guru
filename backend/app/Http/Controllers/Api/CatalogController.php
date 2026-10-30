<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RecipeCardResource;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\HomeFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController
{
    /** Категории со счётчиком опубликованных рецептов. */
    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->whereNull('parent_id')
            ->with(['children'])
            ->withCount(['recipes' => fn ($q) => $q->published()])
            ->orderBy('position')
            ->get();

        return response()->json([
            'data' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'title' => $c->title,
                'description' => $c->description,
                'recipes_count' => $c->recipes_count,
                'children' => $c->children->map(fn ($ch) => [
                    'id' => $ch->id,
                    'slug' => $ch->slug,
                    'title' => $ch->title,
                ]),
            ]),
        ]);
    }

    public function category(string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $recipes = Recipe::published()
            ->where('category_id', $category->id)
            ->with(['cover', 'category'])
            ->withCount('reviews')
            ->orderByDesc('published_at')
            ->paginate(12);

        return response()->json([
            'data' => [
                'id' => $category->id,
                'slug' => $category->slug,
                'title' => $category->title,
                'description' => $category->description,
            ],
            'recipes' => RecipeCardResource::collection($recipes)->response()->getData(true),
        ]);
    }

    public function tags(): JsonResponse
    {
        return response()->json([
            'data' => Tag::query()
                ->withCount(['recipes' => fn ($q) => $q->published()])
                ->having('recipes_count', '>', 0)
                ->orderByDesc('recipes_count')
                ->get()
                ->map(fn ($t) => [
                    'slug' => $t->slug,
                    'title' => $t->title,
                    'recipes_count' => $t->recipes_count,
                ]),
        ]);
    }

    public function collections(): JsonResponse
    {
        $collections = Collection::where('is_published', true)
            ->with('cover')
            ->withCount('recipes')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $collections->map(fn ($c) => [
                'slug' => $c->slug,
                'title' => $c->title,
                'lead' => $c->lead,
                'recipes_count' => $c->recipes_count,
                'cover' => $c->cover?->url(),
            ]),
        ]);
    }

    public function collection(string $slug): JsonResponse
    {
        $collection = Collection::where('slug', $slug)
            ->where('is_published', true)
            ->with(['cover', 'recipes' => fn ($q) => $q->published()->with(['cover', 'category'])])
            ->firstOrFail();

        return response()->json([
            'data' => [
                'slug' => $collection->slug,
                'title' => $collection->title,
                'lead' => $collection->lead,
                'cover' => $collection->cover?->url(),
                'recipes' => RecipeCardResource::collection($collection->recipes),
            ],
        ]);
    }

    /** Данные главной страницы одним запросом — чтобы SSR не делал четыре. */
    /** Лента видеорецептов: опубликованные рецепты, у которых есть ролик. */
    public function reels(): AnonymousResourceCollection
    {
        $recipes = Recipe::published()
            ->withReel()
            ->with(['cover', 'category'])
            ->orderByDesc('published_at')
            ->paginate(12);

        return RecipeCardResource::collection($recipes);
    }

    public function home(): JsonResponse
    {
        $hero = Recipe::published()
            ->with(['cover', 'category'])
            ->orderByDesc('published_at')
            ->first();

        $feed = app(HomeFeed::class);

        $reels = Recipe::published()
            ->withReel()
            ->with(['cover', 'category'])
            ->orderByDesc('published_at')
            ->take(6)
            ->get();

        return response()->json([
            'data' => [
                'hero' => $hero ? new RecipeCardResource($hero) : null,
                'popular' => RecipeCardResource::collection($feed->popularThisWeek(8)),
                'reels' => RecipeCardResource::collection($reels),
                'collections' => $feed->popularCollections(6),
                'best' => RecipeCardResource::collection($feed->best(6)),
                'most_saved' => RecipeCardResource::collection($feed->mostSaved(6)),
                'top_cooks' => $feed->topCooks(6),
                'categories' => Category::whereNull('parent_id')
                    ->orderBy('position')
                    ->get()
                    ->map(fn ($c) => ['slug' => $c->slug, 'title' => $c->title]),
            ],
        ]);
    }
}
