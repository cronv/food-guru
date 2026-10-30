<?php

namespace App\Http\Controllers\Api;

use App\Enums\RatingLevel;
use App\Enums\RatingSource;
use App\Enums\UserRole;
use App\Http\Resources\LeaderboardRowResource;
use App\Models\User;
use App\Services\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatingController
{
    public function __construct(private RatingService $rating) {}

    /**
     * Таблица лидеров. Открыта всем, поэтому в строке нет ни почты, ни роли.
     *
     * В таблицу попадают только подтверждённые и не заблокированные:
     * иначе наверх выйдет кто-то, кто даже не дошёл до письма.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level' => ['nullable', 'string', 'in:novice,cook,chef,guru'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // Только читатели: редакция получает баллы за рабочие публикации.
        $query = User::query()
            ->where('role', UserRole::User)
            ->whereNotNull('email_verified_at')
            ->where('is_active', true)
            ->where('rating_points', '>', 0)
            ->withCount(['recipes', 'reviews']);

        if (! empty($data['level'])) {
            $query->where('rating_level', $data['level']);
        }

        $perPage = 25;
        $page = max(1, (int) ($data['page'] ?? 1));

        // Вторая сортировка по id: без неё строки с равными баллами
        // будут прыгать между страницами.
        $users = $query->orderByDesc('rating_points')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // Позиция считается от начала страницы — так она честная и без лишнего запроса.
        $offset = ($users->currentPage() - 1) * $users->perPage();
        foreach ($users as $i => $user) {
            $user->position = $offset + $i + 1;
        }

        return response()->json([
            'data' => LeaderboardRowResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'levels' => $this->levels(),
                // За что дают баллы — справка на сайте берёт цифры отсюда, а не держит копию.
                'sources' => collect(RatingSource::cases())->map(fn (RatingSource $s) => [
                    'value' => $s->value,
                    'label' => $s->label(),
                    'points' => $s->points(),
                ])->values(),
            ],
        ]);
    }

    /** Свой рейтинг: баллы, уровень, серия заходов и из чего всё сложилось. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // Место — среди тех же, кто в таблице лидеров: только читатели.
        $position = User::where('role', UserRole::User)
            ->whereNotNull('email_verified_at')
            ->where('is_active', true)
            ->where(function ($q) use ($user) {
                $q->where('rating_points', '>', $user->rating_points)
                    ->orWhere(function ($q) use ($user) {
                        $q->where('rating_points', $user->rating_points)
                            ->where('id', '<', $user->id);
                    });
            })
            ->count() + 1;

        return response()->json([
            'data' => [
                'points' => (int) $user->rating_points,
                'level' => $user->rating_level->value,
                'level_label' => $user->rating_level->label(),
                'next_level' => $user->rating_level->next()?->value,
                'next_level_label' => $user->rating_level->next()?->label(),
                'points_to_next' => $user->pointsToNextLevel(),
                'progress' => round($user->levelProgress(), 3),
                'streak_days' => (int) $user->streak_days,
                'streak_best' => (int) $user->streak_best,
                'visits_total' => (int) $user->visits_total,
                // У сотрудника редакции места в таблице нет — баллы видны, место нет.
                'position' => $user->rating_points > 0 && $user->role === UserRole::User ? $position : null,
                'breakdown' => $this->rating->breakdown($user),
            ],
            'meta' => ['levels' => $this->levels()],
        ]);
    }

    /** Шкала уровней — чтобы фронтенд не держал пороги у себя. */
    private function levels(): array
    {
        return collect(RatingLevel::cases())
            ->map(fn (RatingLevel $level) => [
                'value' => $level->value,
                'label' => $level->label(),
                'threshold' => $level->threshold(),
            ])
            ->all();
    }
}
