<?php

namespace App\Enums;

enum RecipeStatus: string
{
    case Draft = 'draft';
    case Moderation = 'moderation';
    case Published = 'published';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Moderation => 'На модерации',
            self::Published => 'Опубликован',
            self::Rejected => 'Возвращён',
            self::Archived => 'Архив',
        };
    }

    /** Разрешённые переходы статуса. Всё остальное — ошибка 422. */
    public function canMoveTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Moderation, self::Archived],
            self::Moderation => [self::Published, self::Rejected, self::Draft],
            self::Published => [self::Archived, self::Draft],
            self::Rejected => [self::Moderation, self::Draft, self::Archived],
            self::Archived => [self::Draft],
        }, true);
    }
}
