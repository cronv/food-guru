<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\EmailTokenType;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\EmailTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Email;

class LoginLinkController
{
    public function __construct(private EmailTokenService $tokens) {}

    /** Шаг 1: запросить ссылку для входа. */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255', new Email],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        // Ссылка уходит только на подтверждённый адрес активного аккаунта,
        // но ответ одинаков в любом случае.
        if ($user && $user->is_active && $user->hasVerifiedEmail()) {
            if ($this->tokens->nextAllowedAt($user, EmailTokenType::Login) === null) {
                $this->tokens->sendLoginLink($user);
            }
        }

        return response()->json([
            'data' => [
                'status' => 'sent',
                'email' => $email,
                'cooldown_seconds' => config('food_guru.tokens.resend_cooldown_seconds'),
            ],
        ], 202);
    }

    /** Шаг 2: обменять токен на сессию. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $token = $this->tokens->find($data['token'], EmailTokenType::Login);

        if (! $token) {
            return response()->json([
                'message' => 'Ссылка устарела или уже использована.',
                'code' => 'token_expired',
            ], 410);
        }

        $user = $token->user;

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Аккаунт отключён. Напишите в поддержку.',
                'code' => 'account_disabled',
            ], 403);
        }

        // Сначала гасим ссылку, потом входим: из двух одновременных переходов войдёт один.
        if (! $this->tokens->consume($token)) {
            return response()->json([
                'message' => 'Ссылка устарела или уже использована.',
                'code' => 'token_expired',
            ], 410);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        ActivityLog::record($user, 'auth.login', $user);

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }
}
