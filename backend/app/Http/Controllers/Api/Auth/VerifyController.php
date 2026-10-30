<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\EmailTokenType;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Services\EmailTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VerifyController
{
    public function __construct(private EmailTokenService $tokens) {}

    /** Обменивает токен из письма на открытую сессию. */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $token = $this->tokens->find($data['token'], EmailTokenType::Verify);

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

        $consumed = DB::transaction(function () use ($token, $user) {
            // Атомарно: из двух одновременных переходов по ссылке сработает один.
            if (! $this->tokens->consume($token)) {
                return false;
            }

            // Токен мог подтверждать смену адреса.
            if ($user->pending_email && $user->pending_email === $token->email) {
                $user->email = $token->email;
                $user->pending_email = null;
            }

            if (! $user->hasVerifiedEmail()) {
                $user->email_verified_at = now();
            }

            $user->save();

            ActivityLog::record($user, 'email.verified', $user);

            return true;
        });

        if (! $consumed) {
            return response()->json([
                'message' => 'Ссылка устарела или уже использована.',
                'code' => 'token_expired',
            ], 410);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return response()->json([
            'data' => new UserResource($user->fresh()),
        ]);
    }
}
