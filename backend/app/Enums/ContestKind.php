<?php

namespace App\Enums;

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

    /** Что человек прикладывает к работе. */
    public function mimes(): array
    {
        return match ($this) {
            self::Photo, self::Recipe => ['jpeg', 'jpg', 'png', 'webp'],
            self::Video => ['mp4', 'webm', 'mov'],
        };
    }

    /** Предел размера одного файла в килобайтах. */
    public function maxFileKb(): int
    {
        return $this === self::Video ? 102400 : 8192;
    }

    public function maxFiles(): int
    {
        return $this === self::Video ? 1 : 5;
    }
}
