<?php

declare(strict_types=1);

namespace App\Enum;

enum AcademyLevel: string
{
    case Basic = 'basic';
    case Medium = 'medium';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Начальный',
            self::Medium => 'Средний',
            self::Advanced => 'Продвинутый',
        };
    }
}
