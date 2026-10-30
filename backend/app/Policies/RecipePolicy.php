<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    /** Опубликованное видно всем; черновики — автору и редакции. */
    public function view(?User $user, Recipe $recipe): bool
    {
        if ($recipe->isPublic()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $this->isOwner($user, $recipe) || $user->atLeast(UserRole::EditorLead);
    }

    public function create(User $user): bool
    {
        return $user->atLeast(UserRole::ContentManager);
    }

    /** Контент-менеджер правит только свои записи. */
    public function update(User $user, Recipe $recipe): bool
    {
        if ($user->atLeast(UserRole::EditorLead)) {
            return true;
        }

        return $user->atLeast(UserRole::ContentManager) && $this->isOwner($user, $recipe);
    }

    public function submit(User $user, Recipe $recipe): bool
    {
        return $this->update($user, $recipe);
    }

    /** Публиковать и возвращать на доработку может только руководитель и выше. */
    public function publish(User $user, Recipe $recipe): bool
    {
        return $user->atLeast(UserRole::EditorLead);
    }

    public function reject(User $user, Recipe $recipe): bool
    {
        return $this->publish($user, $recipe);
    }

    public function archive(User $user, Recipe $recipe): bool
    {
        return $this->publish($user, $recipe);
    }

    private function isOwner(User $user, Recipe $recipe): bool
    {
        return $recipe->author_id === $user->id;
    }
}
