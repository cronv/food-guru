<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\JournalIssue;
use App\Models\User;

class JournalPolicy
{
    public function view(?User $user, JournalIssue $issue): bool
    {
        if ($issue->isPublic()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $issue->author_id === $user->id || $user->atLeast(UserRole::EditorLead);
    }

    public function create(User $user): bool
    {
        return $user->atLeast(UserRole::ContentManager);
    }

    /** Контент-менеджер правит только свои выпуски. */
    public function update(User $user, JournalIssue $issue): bool
    {
        if ($user->atLeast(UserRole::EditorLead)) {
            return true;
        }

        return $user->atLeast(UserRole::ContentManager) && $issue->author_id === $user->id;
    }

    public function publish(User $user, JournalIssue $issue): bool
    {
        return $user->atLeast(UserRole::EditorLead);
    }
}
