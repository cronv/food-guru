<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\UserResource;
use App\Mail\SecurityNoticeMail;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class PasswordController
{
    /**
     * Задать пароль впервые или сменить имеющийся.
     *
     * Первый пароль текущего не требует: человек только что вошёл по ссылке
     * из письма, и письмо уже подтвердило его личность. Смена существующего
     * требует текущий пароль — иначе чужой открытый браузер даёт захват аккаунта.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $rules = [
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];

        if ($user->hasPassword()) {
            $rules['current_password'] = ['required', 'string'];
        }

        $data = $request->validate($rules, [
            'password.required' => 'Придумайте пароль.',
            'password.confirmed' => 'Пароли не совпадают.',
            'password.min' => 'Пароль должен быть не короче 8 символов.',
            'password.letters' => 'В пароле нужна хотя бы одна буква.',
            'password.numbers' => 'В пароле нужна хотя бы одна цифра.',
            'current_password.required' => 'Введите текущий пароль.',
        ]);

        if ($user->hasPassword() && ! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Текущий пароль указан неверно.',
                'errors' => ['current_password' => ['Текущий пароль указан неверно.']],
            ], 422);
        }

        $wasSet = $user->hasPassword();
        $user->setPassword($data['password']);

        // Остальные устройства выходят: если пароль меняют из-за утечки, старые сессии не должны жить.
        $user->endSessions($request->hasSession() ? $request->session()->getId() : null);

        Mail::to($user->email)->queue(new SecurityNoticeMail(
            $user->name,
            $wasSet ? 'Пароль изменён' : 'Пароль создан',
            $wasSet
                ? 'Пароль от вашего аккаунта только что изменили. Все остальные устройства вышли из аккаунта.'
                : 'Для вашего аккаунта только что задали пароль — теперь можно входить по почте и паролю.',
        ));

        ActivityLog::record($user, $wasSet ? 'password.changed' : 'password.created', $user);

        return response()->json(['data' => new UserResource($user->fresh())]);
    }
}
