<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Подборки для главной. Каждая отвечает на свой вопрос, поэтому и сортировка своя:
 *  - популярное за неделю — что делают с рецептом прямо сейчас;
 *  - лучшие — как его оценили, а не сколько раз открыли;
 *  - рейтинг рецептосохранялки — сколько людей сохранили его себе.
 */
class HomeFeed
{
    /** Каталожная выборка: опубликованные, с тем, что нужно карточке. */
    private function cards(): Builder
    {
        return Recipe::published()
            ->with(['cover', 'category'])
            ->withCount(['savedBy as saves_count', 'reviews'])
            ->withAvg('reviews', 'rating');
    }

    /**
     * Популярное на этой неделе: сохранения, лайки и отзывы за последние 7 дней.
     * Сохранение весит больше — его делают, когда собираются готовить.
     * Если за неделю тихо, порядок держат накопленные лайки и просмотры.
     */
    public function popularThisWeek(int $limit = 8): SupportCollection
    {
        $since = now()->subDays(7);

        return $this->cards()
            ->withCount([
                'savedBy as week_saves' => fn ($q) => $q->where('cookbook_recipes.created_at', '>=', $since),
                'reactions as week_likes' => fn ($q) => $q->where('reaction', 'like')->where('created_at', '>=', $since),
                'reviews as week_reviews' => fn ($q) => $q->where('created_at', '>=', $since),
            ])
            ->orderByRaw('(week_saves * 3 + week_likes * 2 + week_reviews * 2) desc')
            ->orderByDesc('likes_count')
            ->orderByDesc('views_count')
            ->orderByDesc('published_at')
            ->take($limit)
            ->get();
    }

    /**
     * Лучшие: доля лайков со сглаживанием (+1 лайк и +1 дизлайк к каждому).
     * Без сглаживания рецепт с одним лайком (100%) обошёл бы рецепт с 95 из 100.
     */
    public function best(int $limit = 6): SupportCollection
    {
        return $this->cards()
            ->orderByRaw('(likes_count + 1.0) / (likes_count + dislikes_count + 2.0) desc')
            ->orderByDesc('likes_count')
            ->orderByDesc('published_at')
            ->take($limit)
            ->get();
    }

    /** Рейтинг по добавлению в рецептосохранялку. Ни разу не сохранённые сюда не попадают. */
    public function mostSaved(int $limit = 6): SupportCollection
    {
        return $this->cards()
            ->whereHas('savedBy')
            ->orderByDesc('saves_count')
            ->orderByDesc('published_at')
            ->take($limit)
            ->get();
    }

    /**
     * Популярные подборки: по сумме сохранений их рецептов. Обложка — своя
     * у подборки, а если её нет — обложка первого рецепта в ней.
     */
    public function popularCollections(int $limit = 6): array
    {
        $saves = DB::table('collection_recipe')
            ->join('cookbook_recipes', 'cookbook_recipes.recipe_id', '=', 'collection_recipe.recipe_id')
            ->whereColumn('collection_recipe.collection_id', 'collections.id')
            ->selectRaw('count(*)');

        $collections = Collection::where('is_published', true)
            ->with('cover')
            ->withCount(['recipes' => fn ($q) => $q->published()])
            ->selectSub($saves, 'saves_total')
            ->addSelect('collections.*')
            ->orderByDesc('saves_total')
            ->orderBy('id')
            ->take($limit)
            ->get();

        return $collections->map(function (Collection $c) {
            $cover = $c->cover;

            if (! $cover) {
                $mediaId = Recipe::published()
                    ->join('collection_recipe', 'collection_recipe.recipe_id', '=', 'recipes.id')
                    ->where('collection_recipe.collection_id', $c->id)
                    ->whereNotNull('recipes.cover_media_id')
                    ->orderBy('collection_recipe.position')
                    ->value('recipes.cover_media_id');

                $cover = $mediaId ? Media::find($mediaId) : null;
            }

            return [
                'slug' => $c->slug,
                'title' => $c->title,
                'lead' => $c->lead,
                'recipes_count' => $c->recipes_count,
                'saves_total' => (int) $c->saves_total,
                'cover' => $cover ? ['url' => $cover->url(), 'alt' => $cover->alt] : null,
            ];
        })->all();
    }

    /**
     * Топ кулинаров — те же правила, что у таблицы лидеров: баллы рейтинга.
     * Только читатели: редакция набирает баллы за рабочие публикации и заняла бы весь верх.
     */
    public function topCooks(int $limit = 6): array
    {
        return User::query()
            ->where('role', UserRole::User)
            ->whereNotNull('email_verified_at')
            ->where('is_active', true)
            ->where('rating_points', '>', 0)
            ->withCount([
                'recipes' => fn ($q) => $q->published(),
                'reviews',
            ])
            ->orderByDesc('rating_points')
            ->orderBy('id')
            ->take($limit)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'rating_points' => (int) $u->rating_points,
                'rating_label' => $u->rating_level->label(),
                'recipes_count' => (int) $u->recipes_count,
                'reviews_count' => (int) $u->reviews_count,
            ])
            ->all();
    }
}
