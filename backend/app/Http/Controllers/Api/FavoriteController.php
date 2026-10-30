<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RecipeCardResource;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FavoriteController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $recipes = $request->user()->favorites()
            ->published()
            ->with(['cover', 'category'])
            ->paginate(12);

        return RecipeCardResource::collection($recipes);
    }

    public function store(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($recipe->isPublic(), 404);

        // syncWithoutDetaching, чтобы повторное нажатие не ломалось на уникальном ключе.
        $request->user()->favorites()->syncWithoutDetaching([
            $recipe->id => ['created_at' => now()],
        ]);

        return response()->json(['data' => ['favorite' => true]]);
    }

    public function destroy(Request $request, Recipe $recipe): JsonResponse
    {
        $request->user()->favorites()->detach($recipe->id);

        return response()->json(['data' => ['favorite' => false]]);
    }
}
