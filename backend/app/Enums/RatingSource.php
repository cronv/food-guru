<?php

namespace App\Enums;

/**
 * Откуда берутся баллы. Цена повода задана здесь и больше нигде:
 * поменять её значит поменять одну строку, а не искать по контроллерам.
 */
enum RatingSource: string
{
    case Visit = 'visit';
    case Streak = 'streak';
    case Recipe = 'recipe';
    case Comment = 'comment';
    case PhotoReport = 'photo_report';

    public function points(): int
    {
        return match ($this) {
            self::Visit => 1,
            // Бонус за каждую полную неделю подряд идущих заходов.
            self::Streak => 5,
            self::Recipe => 25,
            self::Comment => 5,
            // Фотоотчёт идёт сверх обычного комментария: 5 + 10 = 15.
            self::PhotoReport => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Visit => 'Заход на сайт',
            self::Streak => 'Неделя без пропусков',
            self::Recipe => 'Опубликованный рецепт',
            self::Comment => 'Отзыв к рецепту',
            self::PhotoReport => 'Фотоотчёт о приготовлении',
        };
    }

    /** Подпись для раздела «из чего сложились баллы». */
    public function unit(): string
    {
        return match ($this) {
            self::Visit => 'дней',
            self::Streak => 'недель',
            self::Recipe => 'рецептов',
            self::Comment => 'отзывов',
            self::PhotoReport => 'фотоотчётов',
        };
    }
}
