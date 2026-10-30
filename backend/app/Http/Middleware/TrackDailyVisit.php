<?php

namespace App\Http\Middleware;

use App\Services\RatingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отмечает ежедневный заход и начисляет за него балл.
 *
 * Висит на всех авторизованных маршрутах, поэтому обязан быть дешёвым:
 * внутри сначала сравнение даты в уже загруженной модели и только потом,
 * раз в сутки, запись в базу.
 *
 * Отметка ставится после ответа: заход не должен задерживать отдачу страницы,
 * а если начисление упадёт, человек всё равно получит свой ответ.
 */
class TrackDailyVisit
{
    public function __construct(private RatingService $rating) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user || ! $user->hasVerifiedEmail()) {
            return;
        }

        // Уже отмечались сегодня — ничего не делаем, в базу не ходим.
        if ($user->last_visit_on && $user->last_visit_on->isToday()) {
            return;
        }

        try {
            $this->rating->registerVisit($user);
        } catch (\Throwable $e) {
            // Баллы — не то, ради чего стоит ронять запрос.
            report($e);
        }
    }
}
