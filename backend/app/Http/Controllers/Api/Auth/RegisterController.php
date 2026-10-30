<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\EmailTokenType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\EmailTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Email;

class RegisterController
{
    public function __construct(private EmailTokenService $tokens) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'string', 'max:255', new Email],
        ], [
            'name.required' => 'Укажите имя.',
            'name.min' => 'Имя слишком короткое.',
            'email.required' => 'Укажите адрес почты.',
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'role' => UserRole::User,
            ]);

            $this->tokens->sendVerification($user);
        } elseif (! $user->hasVerifiedEmail()) {
            // Повторная регистрация на неподтверждённый адрес — просто новое письмо.
            if ($this->tokens->nextAllowedAt($user, EmailTokenType::Verify) === null) {
                $this->tokens->sendVerification($user);
            }
        } else {
            // Адрес уже подтверждён: отправляем ссылку для входа, а не отказ.
            if ($this->tokens->nextAllowedAt($user, EmailTokenType::Login) === null) {
                $this->tokens->sendLoginLink($user);
            }
        }

        // Ответ одинаков во всех трёх случаях: форма не должна работать
        // как способ проверить, зарегистрирован ли человек на сайте.
        return response()->json([
            'data' => [
                'status' => 'sent',
                'email' => $email,
                'cooldown_seconds' => config('food_guru.tokens.resend_cooldown_seconds'),
            ],
        ], 202);
    }
}
