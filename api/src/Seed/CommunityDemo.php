<?php

declare(strict_types=1);

namespace App\Seed;

use App\Catalog\PublishedRecipes;
use App\Enum\RatingSource;
use App\Enum\UserRole;
use App\Rating\RatingService;
use Doctrine\DBAL\Connection;

/**
 * Демо-сообщество: читатели, которые сохраняют, оценивают и обсуждают рецепты.
 * Без них блоки главной («популярное», «лучшие», «рейтинг», «топ кулинаров») пустые.
 *
 * Баллы начисляются через RatingService — по тем же правилам, что на сайте.
 * Случайность зафиксирована: mt_srand(2026), каждый запуск даёт одну и ту же картину.
 */
final class CommunityDemo
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

    public function __construct(
        private readonly Connection $db,
        private readonly DemoStore $store,
        private readonly RatingService $rating,
    ) {
    }

    public function seed(): void
    {
        mt_srand(2026);

        $recipes = $this->db->fetchAllAssociative(
            'SELECT r.id, r.author_id FROM recipes r WHERE '.PublishedRecipes::SQL.' ORDER BY r.id',
            ['now' => $this->store->at()],
        );
        if ([] === $recipes) {
            return;
        }

        // У каждого рецепта своя «притягательность»: так рейтинги расходятся, а не выходят ровными.
        $appeal = [];
        foreach ($recipes as $recipe) {
            $appeal[(int) $recipe['id']] = mt_rand(20, 90) / 100;
        }

        // Авторам — баллы за опубликованные рецепты, как при публикации.
        foreach ($recipes as $recipe) {
            $this->rating->award((int) $recipe['author_id'], RatingSource::Recipe, 'Recipe', (int) $recipe['id'], "recipe:{$recipe['id']}");
        }

        foreach (self::COOKS as $i => $name) {
            $cookId = $this->store->user($name, \sprintf('cook%02d@food-guru.local', $i + 1), UserRole::User->value, $this->store->at('-60 days'));

            // Активность у всех разная: от заглянувших пару раз до завсегдатаев.
            $activity = mt_rand(30, 100) / 100;
            $this->visits($cookId, (int) round(28 * $activity));

            $reviewed = 0;
            foreach ($recipes as $recipe) {
                $recipeId = (int) $recipe['id'];
                $chance = $appeal[$recipeId] * $activity;

                if (mt_rand() / mt_getrandmax() < $chance) {
                    $this->db->insert('cookbook_recipes', ['user_id' => $cookId, 'recipe_id' => $recipeId, 'created_at' => $this->recent()]);
                }

                // Избранное — реже: «нравится» ставят не всему, что собираются готовить.
                if (mt_rand() / mt_getrandmax() < $chance * 0.5) {
                    $this->db->insert('favorites', ['user_id' => $cookId, 'recipe_id' => $recipeId, 'created_at' => $this->recent()]);
                }

                if (mt_rand() / mt_getrandmax() < $chance * 0.9) {
                    // Слабые рецепты чаще получают дизлайк.
                    $reaction = mt_rand() / mt_getrandmax() < 0.25 * (1 - $appeal[$recipeId]) ? 'dislike' : 'like';
                    $at = $this->recent();
                    $this->db->insert('recipe_reactions', [
                        'recipe_id' => $recipeId,
                        'user_id' => $cookId,
                        'reaction' => $reaction,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                }

                if ($reviewed < 2 && mt_rand() / mt_getrandmax() < $chance * 0.25) {
                    [$stars, $body] = self::REVIEWS[mt_rand(0, \count(self::REVIEWS) - 1)];
                    $createdAt = $this->recent();
                    $this->db->insert('reviews', [
                        'recipe_id' => $recipeId,
                        'user_id' => $cookId,
                        'rating' => $stars,
                        'body' => $body,
                        'status' => 'published',
                        'created_at' => $createdAt,
                        'updated_at' => $this->store->at(),
                    ]);
                    $reviewId = (int) $this->db->lastInsertId();
                    $this->rating->awardForReview($cookId, $reviewId, $recipeId, false);
                    ++$reviewed;
                }
            }
        }

        $this->recount($recipes);
    }

    /** Заходы за последние дни подряд — с бонусом за каждую полную неделю. */
    private function visits(int $userId, int $days): void
    {
        for ($d = $days; $d >= 1; --$d) {
            $day = $this->store->day("-{$d} days");
            $this->rating->award($userId, RatingSource::Visit, null, null, "visit:{$userId}:{$day}");

            if (0 === $d % 7) {
                $this->rating->award($userId, RatingSource::Streak, null, null, "streak:{$userId}:{$day}");
            }
        }

        $this->db->update('users', [
            'last_visit_on' => $this->store->day('-1 day'),
            'streak_days' => $days,
            'streak_best' => $days,
            'visits_total' => $days,
        ], ['id' => $userId]);
    }

    /** Время в пределах трёх недель: часть событий попадает в «популярное за неделю». */
    private function recent(): string
    {
        return $this->store->at('-'.mt_rand(30, 60 * 24 * 21).' minutes');
    }

    /**
     * Счётчики оценок — из таблицы оценок; просмотры — правдоподобно больше сохранений.
     *
     * @param list<array<string, mixed>> $recipes
     */
    private function recount(array $recipes): void
    {
        foreach ($recipes as $recipe) {
            $recipeId = (int) $recipe['id'];
            $counts = $this->db->fetchAllKeyValue(
                'SELECT reaction, COUNT(*) FROM recipe_reactions WHERE recipe_id = ? GROUP BY reaction',
                [$recipeId],
            );
            $saves = (int) $this->db->fetchOne('SELECT COUNT(*) FROM cookbook_recipes WHERE recipe_id = ?', [$recipeId]);

            $this->db->update('recipes', [
                'likes_count' => (int) ($counts['like'] ?? 0),
                'dislikes_count' => (int) ($counts['dislike'] ?? 0),
                'views_count' => $saves * mt_rand(9, 16) + mt_rand(20, 120),
            ], ['id' => $recipeId]);
        }
    }
}
