<?php

declare(strict_types=1);

namespace App\Enum;

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

    /** Эфир идёт в назначенное время; видеоурок — запись без времени старта. */
    public function isLive(): bool
    {
        return self::Lesson !== $this;
    }
}
