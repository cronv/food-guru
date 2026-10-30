<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Email;

/** Вход по почте и паролю — второй способ рядом со ссылкой из письма. */
class PasswordLoginController
{
    /** Попыток с одной связки «адрес + IP» в минуту. */
    private const ATTEMPTS = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255', new Email],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $key = 'password-login:'.sha1($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            return response()->json([
                'message' => 'Слишком много попыток. Подождите минуту или войдите по ссылке из письма.',
                'code' => 'too_many_attempts',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $user = User::where('email', $email)->first();

        // Хеш проверяется и для несуществующего адреса: иначе по времени ответа
        // можно отличить «нет такого аккаунта» от «неверный пароль».
        $valid = Hash::check($data['password'], $user->password ?? $this->dummyHash());

        if (! $user || ! $user->hasPassword() || ! $valid || ! $user->hasVerifiedEmail()) {
            RateLimiter::hit($key, 60);

            // Один и тот же ответ на любую причину отказа.
            return response()->json([
                'message' => 'Неверная почта или пароль.',
                'code' => 'invalid_credentials',
            ], 422);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Аккаунт отключён. Напишите в поддержку.',
                'code' => 'account_disabled',
            ], 403);
        }

        RateLimiter::clear($key);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        ActivityLog::record($user, 'auth.login_password', $user);

        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * Настоящий хеш случайной строки, собранный с той же стоимостью, что и пароли
     * пользователей. Выдуманная строка не подходит: bcrypt отвергает её формат
     * сразу, и ответ для чужого адреса стал бы быстрее и заметнее остальных.
     */
    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }
}
