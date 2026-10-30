<?php

namespace Database\Seeders;

use App\Enums\RatingSource;
use App\Enums\UserRole;
use App\Models\Recipe;
use App\Models\RecipeReaction;
use App\Models\Review;
use App\Models\User;
use App\Services\RatingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Демо-сообщество: читатели, которые сохраняют, оценивают и обсуждают рецепты.
 * Без них блоки главной («популярное», «лучшие», «рейтинг рецептосохранялки»,
 * «топ кулинаров») пустые.
 *
 * Баллы начисляются через RatingService — по тем же правилам, что на сайте,
 * поэтому таблица лидеров и счётчики сходятся. Случайность зафиксирована:
 * каждый запуск даёт одну и ту же картину.
 *
 * Только для разработки и витрины. Перед боевым запуском — не запускать.
 */
class CommunitySeeder extends Seeder
{
    private const COOKS = [
        'Елена Смирнова', 'Дмитрий Орлов', 'Наталья Белова', 'Алексей Громов',
        'Светлана Кузнецова', 'Павел Соколов', 'Ирина Морозова', 'Артём Лебедев',
        'Татьяна Волкова', 'Максим Никитин', 'Юлия Фёдорова', 'Олег Зайцев',
        'Марина Павлова', 'Кирилл Семёнов',
    ];

    private const REVIEWS = [
        [5, 'Готовила по граммовкам один в один — получилось с первого раза.'],
        [5, 'Подсказки с температурой очень выручили, раньше всегда пересушивала.'],
        [4, 'Вкусно. В следующий раз положу чуть меньше соли.'],
        [5, 'Сделал на ужин для всей семьи, попросили повторить.'],
        [4, 'Хороший рецепт, но по времени у меня вышло минут на десять дольше.'],
        [5, 'Пересчёт порций — то, чего не хватало: готовила на шестерых.'],
    ];

    public function run(): void
    {
        mt_srand(2026);

        $rating = app(RatingService::class);
        $recipes = Recipe::published()->orderBy('id')->get();

        if ($recipes->isEmpty()) {
            return;
        }

        // У каждого рецепта своя «притягательность»: так рейтинги расходятся, а не выходят ровными.
        $appeal = $recipes->mapWithKeys(fn (Recipe $r) => [$r->id => mt_rand(20, 90) / 100]);

        // Авторам — баллы за опубликованные рецепты, как при публикации через админку.
        foreach ($recipes as $recipe) {
            $rating->awardForRecipe($recipe->loadMissing('author'));
        }

        foreach (self::COOKS as $i => $name) {
            $cook = User::create([
                'name' => $name,
                'email' => sprintf('cook%02d@food-guru.local', $i + 1),
                'role' => UserRole::User,
                'is_active' => true,
            ]);
            $cook->forceFill(['email_verified_at' => now()->subDays(60)])->save();

            // Активность у всех разная: от заглянувших пару раз до завсегдатаев.
            $activity = mt_rand(30, 100) / 100;

            $this->seedVisits($rating, $cook, (int) round(28 * $activity));

            $reviewed = 0;
            foreach ($recipes as $recipe) {
                $chance = $appeal[$recipe->id] * $activity;

                if (mt_rand() / mt_getrandmax() < $chance) {
                    $recipe->savedBy()->attach($cook->id, ['created_at' => $this->recent()]);
                }

                // Избранное — реже: «нравится» ставят не всему, что собираются готовить.
                if (mt_rand() / mt_getrandmax() < $chance * 0.5) {
                    $recipe->favoredBy()->attach($cook->id, ['created_at' => $this->recent()]);
                }

                if (mt_rand() / mt_getrandmax() < $chance * 0.9) {
                    RecipeReaction::create([
                        'recipe_id' => $recipe->id,
                        'user_id' => $cook->id,
                        // Слабые рецепты чаще получают дизлайк.
                        'reaction' => mt_rand() / mt_getrandmax() < 0.25 * (1 - $appeal[$recipe->id]) ? 'dislike' : 'like',
                        'created_at' => $at = $this->recent(),
                        'updated_at' => $at,
                    ]);
                }

                if ($reviewed < 2 && mt_rand() / mt_getrandmax() < $chance * 0.25) {
                    [$stars, $body] = self::REVIEWS[mt_rand(0, count(self::REVIEWS) - 1)];

                    $review = Review::create([
                        'recipe_id' => $recipe->id,
                        'user_id' => $cook->id,
                        'rating' => $stars,
                        'body' => $body,
                        'status' => 'published',
                    ]);
                    $review->forceFill(['created_at' => $this->recent()])->save();
                    $rating->awardForReview($review);
                    $reviewed++;
                }
            }
        }

        $this->recount($recipes);
    }

    /** Заходы за последние дни подряд — с бонусом за каждую полную неделю. */
    private function seedVisits(RatingService $rating, User $user, int $days): void
    {
        for ($d = $days; $d >= 1; $d--) {
            $day = now()->subDays($d)->toDateString();
            $rating->award($user, RatingSource::Visit, null, "visit:{$user->id}:{$day}");

            if ($d % 7 === 0) {
                $rating->award($user, RatingSource::Streak, null, "streak:{$user->id}:{$day}");
            }
        }

        $user->forceFill([
            'last_visit_on' => now()->subDay()->toDateString(),
            'streak_days' => $days,
            'streak_best' => $days,
            'visits_total' => $days,
        ])->save();
    }

    /** Время в пределах трёх недель: часть событий попадает в «популярное за неделю». */
    private function recent(): \DateTimeInterface
    {
        return now()->subMinutes(mt_rand(30, 60 * 24 * 21));
    }

    /** Счётчики оценок — из таблицы оценок; просмотры — правдоподобно больше сохранений. */
    private function recount($recipes): void
    {
        foreach ($recipes as $recipe) {
            $counts = RecipeReaction::where('recipe_id', $recipe->id)
                ->selectRaw('reaction, count(*) as total')
                ->groupBy('reaction')
                ->pluck('total', 'reaction');

            $saves = DB::table('cookbook_recipes')->where('recipe_id', $recipe->id)->count();

            $recipe->forceFill([
                'likes_count' => (int) ($counts['like'] ?? 0),
                'dislikes_count' => (int) ($counts['dislike'] ?? 0),
                'views_count' => $saves * mt_rand(9, 16) + mt_rand(20, 120),
            ])->save();
        }
    }
}
