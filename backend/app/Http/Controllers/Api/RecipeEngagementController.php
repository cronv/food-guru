<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Recipe;
use App\Models\RecipeReaction;
use App\Models\RecipeReport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Оценки, просмотры и жалобы на рецепт. */
class RecipeEngagementController
{
    /** Поставить или сменить оценку. Одна на человека — повторная заменяет прежнюю. */
    public function react(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($recipe->isPublic(), 404);

        $data = $request->validate([
            'reaction' => ['required', Rule::in([RecipeReaction::LIKE, RecipeReaction::DISLIKE])],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($recipe, $user, $data) {
            $key = ['recipe_id' => $recipe->id, 'user_id' => $user->id];

            try {
                RecipeReaction::updateOrCreate($key, ['reaction' => $data['reaction']]);
            } catch (UniqueConstraintViolationException) {
                // Два одновременных запроса: оба не нашли запись, второй упёрся в индекс.
                RecipeReaction::where($key)->update(['reaction' => $data['reaction']]);
            }

            $this->recount($recipe);
        });

        return $this->state($recipe, $data['reaction']);
    }

    public function unreact(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($recipe->isPublic(), 404);

        DB::transaction(function () use ($recipe, $request) {
            RecipeReaction::where(['recipe_id' => $recipe->id, 'user_id' => $request->user()->id])->delete();
            $this->recount($recipe);
        });

        return $this->state($recipe, null);
    }

    /**
     * Отметить просмотр. Вызывает браузер после загрузки страницы, а не сервер
     * рендера: иначе каждый заход считался бы дважды, а поисковые роботы без
     * JavaScript накручивали бы счётчик.
     *
     * Один просмотр в сутки на человека: для вошедшего — по аккаунту, для гостя —
     * по адресу и браузеру. Cache::add атомарен, так что двойной запрос не проскочит.
     */
    public function view(Request $request, string $slug): JsonResponse
    {
        $recipe = Recipe::published()->where('slug', $slug)->firstOrFail();

        $viewer = $request->user()?->id
            ? 'u'.$request->user()->id
            : 'g'.sha1($request->ip().'|'.$request->userAgent());

        $key = "recipe-view:{$recipe->id}:{$viewer}:".now()->toDateString();

        if (Cache::add($key, true, now()->endOfDay())) {
            $recipe->increment('views_count');
        }

        return response()->json(['data' => ['views_count' => (int) $recipe->fresh()->views_count]]);
    }

    /** Жалоба. Повторная от того же человека, пока первую не разобрали, обновляет её. */
    public function report(Request $request, string $slug): JsonResponse
    {
        $recipe = Recipe::published()->where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(RecipeReport::REASONS))],
            'message' => [
                Rule::requiredIf($request->input('reason') === 'other'),
                'nullable', 'string', 'max:1000',
                ...($request->input('reason') === 'other' ? ['min:10'] : []),
            ],
        ], [
            'reason.required' => 'Выберите причину.',
            'reason.in' => 'Выберите причину из списка.',
            'message.required' => 'Опишите, что не так.',
            'message.min' => 'Опишите проблему хотя бы парой предложений.',
        ]);

        $report = RecipeReport::updateOrCreate(
            ['recipe_id' => $recipe->id, 'user_id' => $request->user()->id, 'status' => 'open'],
            ['reason' => $data['reason'], 'message' => $data['message'] ?? null],
        );

        ActivityLog::record($request->user(), 'recipe.reported', $recipe, [
            'title' => $recipe->title,
            'reason' => $data['reason'],
        ]);

        return response()->json(['data' => ['id' => $report->id, 'status' => 'open']], 201);
    }

    /** Счётчики пересчитываются из таблицы оценок, а не ±1: так они не расходятся. */
    private function recount(Recipe $recipe): void
    {
        $counts = RecipeReaction::where('recipe_id', $recipe->id)
            ->selectRaw('reaction, count(*) as total')
            ->groupBy('reaction')
            ->pluck('total', 'reaction');

        $recipe->forceFill([
            'likes_count' => (int) ($counts[RecipeReaction::LIKE] ?? 0),
            'dislikes_count' => (int) ($counts[RecipeReaction::DISLIKE] ?? 0),
        ])->save();
    }

    private function state(Recipe $recipe, ?string $mine): JsonResponse
    {
        $recipe->refresh();

        return response()->json(['data' => [
            'likes_count' => (int) $recipe->likes_count,
            'dislikes_count' => (int) $recipe->dislikes_count,
            'my_reaction' => $mine,
        ]]);
    }
}
