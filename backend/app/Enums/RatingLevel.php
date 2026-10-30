<?php

namespace App\Enums;

enum RatingLevel: string
{
    case Novice = 'novice';
    case Cook = 'cook';
    case Chef = 'chef';
    case Guru = 'guru';

    public function label(): string
    {
        return match ($this) {
            self::Novice => 'Новичок',
            self::Cook => 'Повар',
            self::Chef => 'Шеф',
            self::Guru => 'Гуру',
        };
    }

    /** Нижняя граница уровня в баллах. */
    public function threshold(): int
    {
        return match ($this) {
            self::Novice => 0,
            self::Cook => 100,
            self::Chef => 400,
            self::Guru => 1200,
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Novice => self::Cook,
            self::Cook => self::Chef,
            self::Chef => self::Guru,
            self::Guru => null,
        };
    }

    /** Уровень по числу баллов. Идём от верхнего, первый подошедший и есть ответ. */
    public static function fromPoints(int $points): self
    {
        foreach ([self::Guru, self::Chef, self::Cook] as $level) {
            if ($points >= $level->threshold()) {
                return $level;
            }
        }

        return self::Novice;
    }
}
