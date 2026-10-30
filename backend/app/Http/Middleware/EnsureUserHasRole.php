<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Использование: ->middleware('role:content_manager')
     * Роли вложены, поэтому проверяется минимальный уровень, а не точное совпадение.
     */
    public function handle(Request $request, Closure $next, string $minimum): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Требуется вход.'], 401);
        }

        $required = UserRole::from($minimum);

        if (! $user->atLeast($required)) {
            return response()->json([
                'message' => 'Недостаточно прав.',
                'code' => 'forbidden_role',
            ], 403);
        }

        return $next($request);
    }
}
