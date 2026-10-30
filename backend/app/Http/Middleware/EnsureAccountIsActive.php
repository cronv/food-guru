<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отключённый аккаунт выходит из сессии при первом же запросе.
 *
 * Раньше is_active проверялся только при входе: открытая сессия и cookie
 * «запомнить меня» продолжали работать — отзывы, жалобы, покупки — бессрочно.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Аккаунт отключён. Если это ошибка — напишите в поддержку.',
                'code' => 'account_disabled',
            ], 403);
        }

        return $next($request);
    }
}
