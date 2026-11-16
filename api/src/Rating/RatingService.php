<?php

declare(strict_types=1);

namespace App\Rating;

use App\Catalog\PublishedRecipes;
use App\Enum\RatingLevel;
use App\Enum\RatingSource;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Баллы пользователя.
 *
 * Главное требование — идемпотентность: один повод не начисляет дважды.
 * Каждое начисление пишется в rating_events с ключом dedup_key, а уникальный
 * индекс отбивает повтор. Проверять «а не начисляли ли мы уже» запросом нельзя:
 * два одновременных запроса пройдут проверку оба.
 */
final class RatingService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Начислить баллы за повод. dedupKey null — начислять каждый раз.
     *
     * @return bool true, если баллы действительно начислены
     */
    public function award(int $userId, RatingSource $source, ?string $subjectType = null, ?int $subjectId = null, ?string $dedupKey = null): bool
    {
        return $this->db->transactional(function () use ($userId, $source, $subjectType, $subjectId, $dedupKey): bool {
            // INSERT IGNORE: уникальный индекс dedup_key — единственная настоящая защита от повтора.
            // NULL в уникальном индексе MySQL дублем не считается — события без ключа вставляются всегда.
            $inserted = $this->db->executeStatement(
                'INSERT IGNORE INTO rating_events (user_id, source, points, subject_type, subject_id, dedup_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $source->value, $source->points(), $subjectType, $subjectId, $dedupKey, PublishedRecipes::now($this->clock->now())],
            );
            if (0 === $inserted) {
                return false;
            }

            // increment() в Eloquent трогает и updated_at — как и здесь.
            $this->db->executeStatement('UPDATE users SET rating_points = rating_points + ?, updated_at = ? WHERE id = ?', [
                $source->points(), PublishedRecipes::now($this->clock->now()), $userId,
            ]);
            $points = (int) $this->db->fetchOne('SELECT rating_points FROM users WHERE id = ?', [$userId]);
            $this->db->executeStatement('UPDATE users SET rating_level = ? WHERE id = ? AND rating_level != ?', [
                RatingLevel::fromPoints($points)->value, $userId, RatingLevel::fromPoints($points)->value,
            ]);

            return true;
        });
    }

    /**
     * Отметить заход на сайт: раз в сутки, с серией дней подряд и бонусом
     * за каждую полную неделю без пропусков.
     */
    public function registerVisit(int $userId): void
    {
        $user = $this->db->fetchAssociative('SELECT last_visit_on, streak_days, streak_best, visits_total FROM users WHERE id = ?', [$userId]);
        if (false === $user) {
            return;
        }

        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        if ($user['last_visit_on'] === $today) {
            return;
        }

        // Заходил вчера — серия продолжается, иначе начинается заново.
        $yesterday = (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
        $streak = $user['last_visit_on'] === $yesterday ? (int) $user['streak_days'] + 1 : 1;

        $this->db->update('users', [
            'last_visit_on' => $today,
            'streak_days' => $streak,
            'streak_best' => max((int) $user['streak_best'], $streak),
            'visits_total' => (int) $user['visits_total'] + 1,
            'updated_at' => PublishedRecipes::now($this->clock->now()),
        ], ['id' => $userId]);

        $this->award($userId, RatingSource::Visit, dedupKey: "visit:{$userId}:{$today}");
        if (0 === $streak % 7) {
            $this->award($userId, RatingSource::Streak, dedupKey: "streak:{$userId}:{$today}");
        }
    }

    /**
     * Баллы за отзыв и за фотоотчёт. Ключ привязан к паре «человек + рецепт»,
     * а не к id отзыва: иначе отзыв можно было бы удалить и написать заново ради баллов.
     */
    public function awardForReview(int $userId, int $reviewId, int $recipeId, bool $withPhotos): void
    {
        $this->award($userId, RatingSource::Comment, 'Review', $reviewId, "comment:{$userId}:{$recipeId}");
        if ($withPhotos) {
            $this->award($userId, RatingSource::PhotoReport, 'Review', $reviewId, "photo:{$userId}:{$recipeId}");
        }
    }

    /**
     * Из чего сложились баллы — для личного кабинета.
     *
     * @return list<array{source: string, label: string, unit: string, count: int, points: int}>
     */
    public function breakdown(int $userId): array
    {
        $rows = [];
        foreach ($this->db->fetchAllAssociative('SELECT source, COUNT(*) AS events, SUM(points) AS points FROM rating_events WHERE user_id = ? GROUP BY source', [$userId]) as $row) {
            $rows[$row['source']] = $row;
        }

        return array_map(static fn (RatingSource $s): array => [
            'source' => $s->value,
            'label' => $s->label(),
            'unit' => $s->unit(),
            'count' => (int) ($rows[$s->value]['events'] ?? 0),
            'points' => (int) ($rows[$s->value]['points'] ?? 0),
        ], RatingSource::cases());
    }
}
