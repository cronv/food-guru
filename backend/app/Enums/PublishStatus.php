<?php

namespace App\Enums;

/**
 * Жизненный цикл редакционной записи: конкурса и занятия академии.
 * У рецептов свой енам со своим переходом «возвращён» — здесь он не нужен:
 * конкурс и эфир готовит сама редакция, присылать их на доработку некому.
 */
enum PublishStatus: string
{
    case Draft = 'draft';
    case Moderation = 'moderation';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Moderation => 'На проверке',
            self::Published => 'Опубликован',
            self::Archived => 'Архив',
        };
    }

    /** Разрешённые переходы статуса. Всё остальное — ошибка 422. */
    public function canMoveTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Moderation, self::Published, self::Archived],
            self::Moderation => [self::Published, self::Draft, self::Archived],
            self::Published => [self::Archived, self::Draft],
            self::Archived => [self::Draft, self::Published],
        }, true);
    }
}
