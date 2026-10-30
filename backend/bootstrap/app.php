<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\TrackDailyVisit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Sanctum на cookie-сессии: оба фронтенда ходят с withCredentials.
        $middleware->statefulApi();

        /*
         * Ограничение частоты для всего API. В Laravel 11 оно не включается
         * само: лимитер «api» объявлен в AppServiceProvider, но без этой
         * строки к маршрутам не применяется, и голосование, отзывы и отправка
         * работ остаются без ограничений вовсе.
         */
        $middleware->throttleApi();

        // На всём API: отключённый аккаунт не может пользоваться старой сессией.
        $middleware->appendToGroup('api', EnsureAccountIsActive::class);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'email.verified' => EnsureEmailIsVerified::class,
            'track.visit' => TrackDailyVisit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
