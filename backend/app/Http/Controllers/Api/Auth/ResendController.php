<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\EmailTokenType;
use App\Models\User;
use App\Services\EmailTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Email;

class ResendController
{
    public function __construct(private EmailTokenService $tokens) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255', new Email],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)
            ->orWhere('pending_email', $email)
            ->first();

        $cooldown = config('food_guru.tokens.resend_cooldown_seconds');

        if ($user && $user->is_active) {
            $type = $user->hasVerifiedEmail() && ! $user->pending_email
                ? EmailTokenType::Login
                : EmailTokenType::Verify;

            // В паузе между письмами молча не шлём повторно. Отдельный ответ
            // «подождите» выдавал бы, что аккаунт с этой почтой существует.
            if (! $this->tokens->nextAllowedAt($user, $type)) {
                $type === EmailTokenType::Login
                    ? $this->tokens->sendLoginLink($user)
                    : $this->tokens->sendVerification($user, $email);
            }
        }

        return response()->json([
            'data' => [
                'status' => 'sent',
                'email' => $email,
                'cooldown_seconds' => $cooldown,
            ],
        ], 202);
    }
}
