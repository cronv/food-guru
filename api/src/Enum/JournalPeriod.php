<?php

declare(strict_types=1);

namespace App\Enum;

/** Периодичность выпуска журнала — как App\Enums\JournalPeriod. */
enum JournalPeriod: string
{
    case Week = 'week';
    case Month = 'month';

    private const NOMINATIVE = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    private const GENITIVE = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    public function label(): string
    {
        return match ($this) {
            self::Week => 'Гайд недели',
            self::Month => 'Гайд месяца',
        };
    }

    /**
     * Подпись периода: «13–19 января 2026» или «Январь 2026».
     * Месяц в двух падежах, потому что в диапазоне дат нужен родительный.
     */
    public function describe(\DateTimeImmutable $date): string
    {
        if (self::Month === $this) {
            return self::NOMINATIVE[(int) $date->format('n')].' '.$date->format('Y');
        }

        // Неделя — с понедельника по воскресенье, как startOfWeek() в Carbon.
        $start = $date->modify('monday this week');
        $end = $start->modify('+6 days');

        // Неделя может лежать на стыке месяцев и даже лет.
        if ($start->format('n') !== $end->format('n')) {
            return $start->format('j').' '.self::GENITIVE[(int) $start->format('n')]
                .' – '.$end->format('j').' '.self::GENITIVE[(int) $end->format('n')].' '.$end->format('Y');
        }

        return $start->format('j').'–'.$end->format('j').' '.self::GENITIVE[(int) $start->format('n')].' '.$start->format('Y');
    }

    public static function monthName(int $month): string
    {
        return self::NOMINATIVE[$month] ?? '';
    }
}
