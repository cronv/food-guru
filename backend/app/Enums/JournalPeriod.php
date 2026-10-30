<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum JournalPeriod: string
{
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Week => 'Гайд недели',
            self::Month => 'Гайд месяца',
        };
    }

    /** Любую дату внутри периода приводим к его началу. */
    public function normalize(Carbon $date): Carbon
    {
        return match ($this) {
            self::Week => $date->copy()->startOfWeek(),
            self::Month => $date->copy()->startOfMonth(),
        };
    }

    /**
     * Подпись периода: «13–19 января 2026» или «Январь 2026».
     * Месяц в двух падежах, потому что в диапазоне дат нужен родительный.
     */
    public function describe(Carbon $date): string
    {
        $nominative = [
            1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
            'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
        ];

        $genitive = [
            1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
            'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
        ];

        if ($this === self::Month) {
            return $nominative[(int) $date->month].' '.$date->year;
        }

        $start = $date->copy()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        // Неделя может лежать на стыке месяцев и даже лет.
        if ($start->month !== $end->month) {
            return $start->day.' '.$genitive[(int) $start->month]
                .' – '.$end->day.' '.$genitive[(int) $end->month].' '.$end->year;
        }

        return $start->day.'–'.$end->day.' '.$genitive[(int) $start->month].' '.$start->year;
    }

    /** Название месяца в именительном падеже — для фильтра по месяцам. */
    public static function monthName(int $month): string
    {
        return [
            1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
            'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
        ][$month] ?? '';
    }
}
