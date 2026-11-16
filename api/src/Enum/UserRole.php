<?php

declare(strict_types=1);

namespace App\Enum;

/** Роли — значения колонки users.role. */
enum UserRole: string
{
    case User = 'user';
    case ContentManager = 'content_manager';
    case EditorLead = 'editor_lead';
    case Admin = 'admin';

    /** Старшинство ролей (метод level()). */
    public function level(): int
    {
        return match ($this) {
            self::User => 0,
            self::ContentManager => 1,
            self::EditorLead => 2,
            self::Admin => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'Пользователь',
            self::ContentManager => 'Контент-менеджер',
            self::EditorLead => 'Руководитель редакции',
            self::Admin => 'Администратор',
        };
    }

    /** Роль для Symfony Security. Младшие роли даёт role_hierarchy в security.yaml. */
    public function securityRole(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }
}
