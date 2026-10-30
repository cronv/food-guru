<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AcademySession;
use App\Models\User;

class AcademyPolicy
{
    public function view(?User $user, AcademySession $session): bool
    {
        if ($session->isPublic()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $session->author_id === $user->id || $user->atLeast(UserRole::EditorLead);
    }

    /**
     * Смотреть эфир и запись может только записавшийся с подтверждённой почтой.
     * Сотрудники редакции — всегда: им нужно проверять материал до эфира.
     */
    public function watch(User $user, AcademySession $session, bool $registered): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->hasVerifiedEmail() && $registered;
    }

    public function create(User $user): bool
    {
        return $user->atLeast(UserRole::ContentManager);
    }

    public function update(User $user, AcademySession $session): bool
    {
        if ($user->atLeast(UserRole::EditorLead)) {
            return true;
        }

        return $user->atLeast(UserRole::ContentManager) && $session->author_id === $user->id;
    }

    public function publish(User $user, AcademySession $session): bool
    {
        return $user->atLeast(UserRole::EditorLead);
    }

    /** Список записавшихся — персональные данные, только руководитель и выше. */
    public function viewRegistrations(User $user, AcademySession $session): bool
    {
        return $user->atLeast(UserRole::EditorLead);
    }
}
