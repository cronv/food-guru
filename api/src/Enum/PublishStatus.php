<?php

declare(strict_types=1);

namespace App\Enum;

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
            self::Moderation => 'На модерации',
            self::Published => 'Опубликован',
            self::Archived => 'В архиве',
        };
    }

    public function canMoveTo(self $next): bool
    {
        return \in_array($next, match ($this) {
            self::Draft => [self::Moderation, self::Published, self::Archived],
            self::Moderation => [self::Published, self::Draft, self::Archived],
            self::Published => [self::Archived, self::Draft],
            self::Archived => [self::Draft, self::Published],
        }, true);
    }
}
