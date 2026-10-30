<?php

namespace App\Enums;

/**
 * Роли строго вложены: каждая следующая умеет всё, что предыдущая.
 */
enum UserRole: string
{
    case User = 'user';
    case ContentManager = 'content_manager';
    case EditorLead = 'editor_lead';
    case Admin = 'admin';

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

    /** Роли, у которых есть доступ в админку. */
    public static function staff(): array
    {
        return [self::ContentManager, self::EditorLead, self::Admin];
    }
}
