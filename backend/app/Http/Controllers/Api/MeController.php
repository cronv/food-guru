<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\UserResource;
use App\Mail\SecurityNoticeMail;
use App\Models\ActivityLog;
use App\Services\EmailTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Email;

class MeController
{
    public function __construct(private EmailTokenService $tokens) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'email' => ['sometimes', 'string', 'max:255', new Email],
        ]);

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }

        $emailChanged = false;

        if (isset($data['email'])) {
            $email = mb_strtolower(trim($data['email']));

            if ($email !== $user->email) {
                // Смена почты — это передача аккаунта: с паролем требуем его,
                // иначе перехваченная сессия уводит аккаунт на чужой адрес.
                if ($user->hasPassword()) {
                    $current = (string) $request->input('current_password');
                    if ($current === '' || ! Hash::check($current, $user->password)) {
                        return response()->json([
                            'message' => 'Чтобы сменить почту, введите текущий пароль.',
                            'errors' => ['current_password' => ['Чтобы сменить почту, введите текущий пароль.']],
                        ], 422);
                    }
                }

                // Основной адрес не трогаем, пока новый не подтверждён:
                // иначе человек потеряет доступ из-за опечатки.
                $user->pending_email = $email;
                $emailChanged = true;
            }
        }

        $user->save();

        if ($emailChanged) {
            $this->tokens->sendVerification($user, $user->pending_email);
            // Старый адрес узнаёт о попытке — и успеет вмешаться, пока новый не подтверждён.
            Mail::to($user->email)->queue(new SecurityNoticeMail(
                $user->name,
                'Запрошена смена почты',
                "Для вашего аккаунта запросили смену почты на {$user->pending_email}. Пока новый адрес не подтверждён, аккаунт остаётся на этом.",
            ));
            ActivityLog::record($user, 'email.change_requested', $user);
        }

        return response()->json([
            'data' => new UserResource($user->fresh()),
            'meta' => ['verification_sent' => $emailChanged],
        ]);
    }
}
