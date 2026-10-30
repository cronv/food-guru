<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Первый администратор на боевом сервере. Пароль не задаётся: человек входит
 * по ссылке из письма на свою почту и там же придумывает пароль.
 */
class MakeAdmin extends Command
{
    protected $signature = 'food-guru:admin {email} {name=Администратор}';

    protected $description = 'Создать администратора или повысить существующий аккаунт';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            $this->error('Некорректный адрес почты.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $created = ! $user->exists;

        if ($created) {
            $user->name = $this->argument('name');
        }

        $user->forceFill([
            'role' => UserRole::Admin,
            'is_active' => true,
            // Почту подтвердит первый же вход по ссылке — она придёт именно на этот адрес.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->info(($created ? 'Создан администратор ' : 'Права администратора выданы: ').$email);
        $this->line('Вход: страница админки → «По ссылке» → письмо придёт на этот адрес.');

        return self::SUCCESS;
    }
}
