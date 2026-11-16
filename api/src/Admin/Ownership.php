<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\User;
use App\Enum\UserRole;

/**
 * Кто может править материал (правило update()): редактор
 * руководит всем, контент-менеджер — только тем, что создал сам.
 */
final class Ownership
{
    /** @param array<string, mixed> $row строка таблицы с колонкой author_id */
    public static function canEdit(User $user, array $row): bool
    {
        return $user->getRole()->atLeast(UserRole::EditorLead)
            || ($user->getRole()->atLeast(UserRole::ContentManager) && (int) $row['author_id'] === (int) $user->getId());
    }
}
