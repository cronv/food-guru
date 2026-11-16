<?php

declare(strict_types=1);

namespace App\Controller\Content;

use App\Api\Validation\InputValidator;
use App\Entity\User;
use App\Enum\RatingLevel;
use App\Enum\RatingSource;
use App\Rating\RatingService;
use App\Security\Attribute\TrackVisit;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Таблица лидеров. Открыта всем,
 * поэтому в строке нет ни почты, ни роли. В таблицу попадают только
 * подтверждённые и не заблокированные читатели: редакция получает баллы
 * за рабочие публикации и заняла бы весь верх.
 */
final class RatingController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly RatingService $rating,
    ) {
    }

    /** Свой рейтинг: баллы, уровень, серия заходов и из чего всё сложилось. */
    #[Route('/me/rating', name: 'rating_me', methods: ['GET'])]
    #[IsGranted('ROLE_USER'), TrackVisit]
    public function me(#[CurrentUser] User $viewer): JsonResponse
    {
        $u = $this->db->fetchAssociative('SELECT id, role, rating_points, rating_level, streak_days, streak_best, visits_total FROM users WHERE id = ?', [$viewer->getId()]);
        \assert(false !== $u);
        $points = (int) $u['rating_points'];
        $level = RatingLevel::from((string) $u['rating_level']);
        $next = $level->next();

        // Место — среди тех же, кто в таблице лидеров: только читатели.
        $position = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM users WHERE role = 'user' AND email_verified_at IS NOT NULL AND is_active = 1
               AND (rating_points > :points OR (rating_points = :points AND id < :id))",
            ['points' => $points, 'id' => $u['id']],
        ) + 1;

        $span = null === $next ? 1 : max(1, $next->threshold() - $level->threshold());
        $progress = null === $next ? 1.0 : min(1.0, max(0.0, ($points - $level->threshold()) / $span));

        return new JsonResponse([
            'data' => [
                'points' => $points,
                'level' => $level->value,
                'level_label' => $level->label(),
                'next_level' => $next?->value,
                'next_level_label' => $next?->label(),
                'points_to_next' => null === $next ? null : max(0, $next->threshold() - $points),
                'progress' => round($progress, 3),
                'streak_days' => (int) $u['streak_days'],
                'streak_best' => (int) $u['streak_best'],
                'visits_total' => (int) $u['visits_total'],
                // У сотрудника редакции места в таблице нет — баллы видны, место нет.
                'position' => $points > 0 && 'user' === $u['role'] ? $position : null,
                'breakdown' => $this->rating->breakdown((int) $u['id']),
            ],
            'meta' => ['levels' => self::levels()],
        ]);
    }

    /** @return list<array{value: string, label: string, threshold: int}> */
    private static function levels(): array
    {
        return array_map(static fn (RatingLevel $l): array => [
            'value' => $l->value, 'label' => $l->label(), 'threshold' => $l->threshold(),
        ], RatingLevel::cases());
    }

    #[Route('/rating', name: 'rating_leaderboard', methods: ['GET'])]
    public function leaderboard(Request $request, #[CurrentUser] ?User $viewer): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'level' => 'nullable|string|in:novice,cook,chef,guru',
            'page' => 'nullable|integer|min:1',
        ]);

        $where = "u.role = 'user' AND u.email_verified_at IS NOT NULL AND u.is_active = 1 AND u.rating_points > 0";
        $params = [];
        if (!empty($data['level'])) {
            $where .= ' AND u.rating_level = :level';
            $params['level'] = $data['level'];
        }

        $page = max(1, (int) ($data['page'] ?? 1));
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM users u WHERE {$where}", $params);
        $offset = ($page - 1) * self::PER_PAGE;

        // withCount(['recipes', 'reviews']) — все рецепты и отзывы человека, без фильтров.
        // Вторая сортировка по id: без неё строки с равными баллами прыгали бы между страницами.
        $rows = $this->db->fetchAllAssociative(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM recipes r WHERE u.id = r.author_id) AS recipes_count,
                    (SELECT COUNT(*) FROM reviews rv WHERE u.id = rv.user_id) AS reviews_count
               FROM users u WHERE {$where}
              ORDER BY u.rating_points DESC, u.id ASC
              LIMIT ".self::PER_PAGE.' OFFSET '.$offset,
            $params,
        );

        $data = [];
        foreach ($rows as $i => $u) {
            $level = RatingLevel::from((string) $u['rating_level']);
            $data[] = [
                'id' => (int) $u['id'],
                'name' => $u['name'],
                'rating_points' => (int) $u['rating_points'],
                'rating_level' => $level->value,
                'rating_label' => $level->label(),
                'streak_days' => (int) $u['streak_days'],
                'recipes_count' => (int) $u['recipes_count'],
                'reviews_count' => (int) $u['reviews_count'],
                // Позиция считается от начала страницы — честно и без лишнего запроса.
                'position' => $offset + $i + 1,
                'is_me' => $viewer?->getId() === (int) $u['id'],
            ];
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / self::PER_PAGE), 1),
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'levels' => self::levels(),
                // За что дают баллы — справка на сайте берёт цифры отсюда, а не держит копию.
                'sources' => array_map(static fn (RatingSource $s): array => [
                    'value' => $s->value, 'label' => $s->label(), 'points' => $s->points(),
                ], RatingSource::cases()),
            ],
        ]);
    }
}
