<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Contest;
use App\Models\User;

class ContestPolicy
{
    /** Опубликованный конкурс виден всем; черновики — автору и редакции. */
    public function view(?User $user, Contest $contest): bool
    {
        if ($contest->isPublic()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $contest->author_id === $user->id || $user->atLeast(UserRole::EditorLead);
    }

    public function create(User $user): bool
    {
        return $user->atLeast(UserRole::ContentManager);
    }

    /** Контент-менеджер правит только свои конкурсы. */
    public function update(User $user, Contest $contest): bool
    {
        if ($user->atLeast(UserRole::EditorLead)) {
            return true;
        }

        return $user->atLeast(UserRole::ContentManager) && $contest->author_id === $user->id;
    }

    /** Публиковать и снимать с публикации может руководитель редакции и выше. */
    public function publish(User $user, Contest $contest): bool
    {
        return $user->atLeast(UserRole::EditorLead);
    }

    /** Разбирать присланные работы может любой сотрудник редакции. */
    public function moderateEntries(User $user, Contest $contest): bool
    {
        return $this->update($user, $contest);
    }

    /** Назначать победителей — только руководитель редакции и выше. */
    public function awardWinners(User $user, Contest $contest): bool
    {
        return $user->atLeast(UserRole::EditorLead);
    }
}
