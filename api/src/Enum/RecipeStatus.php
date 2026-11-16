<?php

declare(strict_types=1);

namespace App\Enum;

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
            self::Rejected => 'Отклонён',
            self::Archived => 'В архиве',
        };
    }

    public function canMoveTo(self $next): bool
    {
        return \in_array($next, match ($this) {
            self::Draft => [self::Moderation, self::Archived],
            self::Moderation => [self::Published, self::Rejected, self::Draft],
            self::Published => [self::Archived, self::Draft],
            self::Rejected => [self::Moderation, self::Draft, self::Archived],
            self::Archived => [self::Draft],
        }, true);
    }
}
