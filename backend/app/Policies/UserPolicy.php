<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->atLeast(UserRole::EditorLead);
    }

    /**
     * Руководитель распоряжается только пользователями и контент-менеджерами —
     * иначе он смог бы понизить администратора или повысить себя.
     */
    public function manage(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role !== UserRole::EditorLead) {
            return false;
        }

        return in_array($target->role, [UserRole::User, UserRole::ContentManager], true);
    }

    /** Какую роль этот пользователь вправе назначить. */
    public function assignRole(User $user, User $target, UserRole $role): bool
    {
        if (! $this->manage($user, $target)) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        return in_array($role, [UserRole::User, UserRole::ContentManager], true);
    }

    public function viewActivity(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
