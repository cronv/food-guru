<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** Пользователь с подтверждённой почтой — большинство действий требуют её. */
    protected function user(UserRole $role = UserRole::User, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Читатель',
            'email' => 'user'.str()->random(8).'@food-guru.local',
            'role' => $role,
            'is_active' => true,
        ], $attributes));

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->refresh();
    }

    protected function unverified(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Новичок',
            'email' => 'new'.str()->random(8).'@food-guru.local',
            'role' => UserRole::User,
            'is_active' => true,
        ], $attributes));
    }
}
