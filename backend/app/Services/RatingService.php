<?php

namespace App\Services;

use App\Enums\RatingLevel;
use App\Enums\RatingSource;
use App\Models\Recipe;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Баллы пользователя.
 *
 * Главное требование — идемпотентность: один и тот же повод не должен
 * начислять дважды. Поэтому каждое начисление пишется в rating_events
 * с ключом dedup_key, а уникальный индекс в базе отбивает повтор.
 * Проверять «а не начисляли ли мы уже» запросом нельзя: два одновременных
 * запроса пройдут проверку оба.
 */
class RatingService
{
    /**
     * Начислить баллы за повод.
     *
     * @param  string|null  $dedupKey  ключ однократности; null — начислять каждый раз
     * @return bool true, если баллы действительно начислены
     */
    public function award(
        User $user,
        RatingSource $source,
        ?Model $subject = null,
        ?string $dedupKey = null,
    ): bool {
        $points = $source->points();

        /*
         * insertOrIgnore, а не firstOrCreate: нам нужна атомарность.
         * В MySQL уникальный индекс не считает NULL за дубль, поэтому
         * события без dedup_key вставляются всегда — так и задумано.
         */
        return DB::transaction(function () use ($user, $source, $points, $subject, $dedupKey) {
            $inserted = DB::table('rating_events')->insertOrIgnore([[
                'user_id' => $user->id,
                'source' => $source->value,
                'points' => $points,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'dedup_key' => $dedupKey,
                'created_at' => now(),
            ]]);

            if ($inserted === 0) {
                return false;
            }

            $user->increment('rating_points', $points);
            $this->syncLevel($user);

            return true;
        });
    }

    /**
     * Отметить заход на сайт. Вызывается на каждом запросе авторизованного
     * пользователя, поэтому первым делом дёшево отсекает повторы за день.
     */
    public function registerVisit(User $user): void
    {
        $today = now()->startOfDay();

        if ($user->last_visit_on && $user->last_visit_on->isSameDay($today)) {
            return;
        }

        // Заходил вчера — серия продолжается, иначе начинается заново.
        $continues = $user->last_visit_on
            && $user->last_visit_on->isSameDay($today->copy()->subDay());

        $streak = $continues ? $user->streak_days + 1 : 1;

        $user->forceFill([
            'last_visit_on' => $today->toDateString(),
            'streak_days' => $streak,
            'streak_best' => max((int) $user->streak_best, $streak),
            'visits_total' => (int) $user->visits_total + 1,
        ])->save();

        $stamp = $today->toDateString();

        $this->award($user, RatingSource::Visit, null, "visit:{$user->id}:{$stamp}");

        // Бонус за каждую полную неделю без пропусков.
        if ($streak % 7 === 0) {
            $this->award($user, RatingSource::Streak, null, "streak:{$user->id}:{$stamp}");
        }
    }

    /**
     * Баллы за отзыв. Ключ привязан к паре «человек + рецепт», а не к id отзыва:
     * иначе отзыв можно было бы удалить и написать заново ради новых баллов.
     */
    public function awardForReview(Review $review): void
    {
        // Автор есть всегда: reviews.user_id удаляется каскадом вместе с пользователем.
        $user = $review->author;

        $this->award(
            $user,
            RatingSource::Comment,
            $review,
            "comment:{$user->id}:{$review->recipe_id}",
        );

        if ($review->media()->exists()) {
            $this->award(
                $user,
                RatingSource::PhotoReport,
                $review,
                "photo:{$user->id}:{$review->recipe_id}",
            );
        }
    }

    /** Баллы автору за опубликованный рецепт. Повторная публикация не считается. */
    public function awardForRecipe(Recipe $recipe): void
    {
        $this->award($recipe->author, RatingSource::Recipe, $recipe, "recipe:{$recipe->id}");
    }

    /**
     * Из чего сложились баллы — для личного кабинета.
     *
     * @return array<int, array{source: string, label: string, unit: string, count: int, points: int}>
     */
    public function breakdown(User $user): array
    {
        $rows = DB::table('rating_events')
            ->where('user_id', $user->id)
            ->selectRaw('source, COUNT(*) as events, SUM(points) as points')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        $result = [];

        foreach (RatingSource::cases() as $source) {
            $row = $rows->get($source->value);

            $result[] = [
                'source' => $source->value,
                'label' => $source->label(),
                'unit' => $source->unit(),
                'count' => (int) ($row->events ?? 0),
                'points' => (int) ($row->points ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Пересчитать баллы из журнала. Нужен после ручных правок в админке
     * и как лекарство, если счётчик и журнал разошлись.
     */
    public function recalculate(User $user): void
    {
        $points = max(0, (int) DB::table('rating_events')->where('user_id', $user->id)->sum('points'));

        $user->forceFill([
            'rating_points' => $points,
            'rating_level' => RatingLevel::fromPoints($points),
        ])->save();
    }

    private function syncLevel(User $user): void
    {
        $level = RatingLevel::fromPoints((int) $user->rating_points);

        if ($user->rating_level !== $level) {
            $user->forceFill(['rating_level' => $level])->save();
        }
    }
}
