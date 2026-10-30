<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RecipeCardResource;
use App\Models\CookbookNote;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Рецептосохранялка: рецепты сайта, сохранённые кнопкой «В рецептосохранялку»,
 * и свои заметки. С избранным не связана — это разные списки.
 */
class CookbookController
{
    /** Предел заметок на человека: книга, а не хранилище файлов. */
    private const LIMIT = 500;

    public function recipes(Request $request): AnonymousResourceCollection
    {
        $recipes = $request->user()->cookbookRecipes()
            ->published()
            ->with(['cover', 'category'])
            ->paginate(12);

        return RecipeCardResource::collection($recipes);
    }

    public function saveRecipe(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($recipe->isPublic(), 404);

        // syncWithoutDetaching: повторное нажатие не падает на первичном ключе.
        $request->user()->cookbookRecipes()->syncWithoutDetaching([
            $recipe->id => ['created_at' => now()],
        ]);

        return $this->recipeState($request, $recipe);
    }

    public function forgetRecipe(Request $request, Recipe $recipe): JsonResponse
    {
        $request->user()->cookbookRecipes()->detach($recipe->id);

        return $this->recipeState($request, $recipe);
    }

    private function recipeState(Request $request, Recipe $recipe): JsonResponse
    {
        return response()->json(['data' => [
            'in_cookbook' => $request->user()->cookbookRecipes()->whereKey($recipe->id)->exists(),
            'saves_count' => $recipe->savedBy()->count(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $notes = $request->user()->cookbookNotes()->latest('updated_at')->paginate(20);

        return response()->json([
            'data' => $notes->getCollection()->map(fn (CookbookNote $n) => $n->toApi()),
            'meta' => [
                'current_page' => $notes->currentPage(),
                'last_page' => $notes->lastPage(),
                'per_page' => $notes->perPage(),
                'total' => $notes->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->cookbookNotes()->count() >= self::LIMIT) {
            return response()->json(['message' => 'В рецептосохранялке уже '.self::LIMIT.' заметок — удалите ненужные.'], 422);
        }

        $note = $user->cookbookNotes()->create($this->validated($request));

        return response()->json(['data' => $note->toApi()], 201);
    }

    public function update(Request $request, CookbookNote $note): JsonResponse
    {
        // Чужая заметка — 404, а не 403: незачем подтверждать, что такая запись есть.
        abort_unless($note->user_id === $request->user()->id, 404);

        $note->update($this->validated($request));

        return response()->json(['data' => $note->toApi()]);
    }

    public function destroy(Request $request, CookbookNote $note): JsonResponse
    {
        abort_unless($note->user_id === $request->user()->id, 404);

        $note->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:200'],
            // Только http(s): javascript: и data: в ссылке превратили бы заметку в ловушку.
            'url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'body' => ['nullable', 'string', 'max:20000'],
        ], [
            'title.required' => 'Назовите рецепт.',
            'url.url' => 'Ссылка должна начинаться с http:// или https://.',
        ]);

        if (empty($data['url']) && empty($data['body'])) {
            abort(response()->json([
                'message' => 'Добавьте ссылку на рецепт или запишите его текст.',
                'errors' => ['body' => ['Добавьте ссылку на рецепт или запишите его текст.']],
            ], 422));
        }

        return $data;
    }
}
