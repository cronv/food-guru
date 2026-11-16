<?php

declare(strict_types=1);

namespace App\Enum;

enum ContestKind: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Recipe = 'recipe';

    public function label(): string
    {
        return match ($this) {
            self::Photo => 'Фото',
            self::Video => 'Видео',
            self::Recipe => 'Рецепт',
        };
    }

    /** @return list<string> */
    public function mimes(): array
    {
        return match ($this) {
            self::Photo, self::Recipe => ['jpeg', 'jpg', 'png', 'webp'],
            self::Video => ['mp4', 'webm', 'mov'],
        };
    }

    public function maxFileKb(): int
    {
        return self::Video === $this ? 102400 : 8192;
    }

    public function maxFiles(): int
    {
        return self::Video === $this ? 1 : 5;
    }
}
