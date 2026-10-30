<?php

namespace App\Enums;

enum AcademyFormat: string
{
    case Webinar = 'webinar';
    case Stream = 'stream';
    case Lesson = 'lesson';

    public function label(): string
    {
        return match ($this) {
            self::Webinar => 'Вебинар',
            self::Stream => 'Прямой эфир',
            self::Lesson => 'Видеоурок',
        };
    }

    /** У видеоурока нет времени старта: он доступен сразу после публикации. */
    public function isLive(): bool
    {
        return $this !== self::Lesson;
    }
}
