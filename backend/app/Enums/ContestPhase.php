<?php

namespace App\Enums;

/**
 * Фаза не хранится в базе — она считается из дат конкурса.
 * Хранить её отдельно значит однажды получить конкурс,
 * у которого приём работ закрыт, а фаза всё ещё «приём работ».
 */
enum ContestPhase: string
{
    case Announced = 'announced';
    case Submissions = 'submissions';
    case Voting = 'voting';
    case Judging = 'judging';
    case Finished = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::Announced => 'Скоро старт',
            self::Submissions => 'Приём работ',
            self::Voting => 'Голосование',
            self::Judging => 'Подводим итоги',
            self::Finished => 'Завершён',
        };
    }

    /** Что человеку делать прямо сейчас. */
    public function hint(): string
    {
        return match ($this) {
            self::Announced => 'Приём работ ещё не начался.',
            self::Submissions => 'Пришлите работу — приём открыт.',
            self::Voting => 'Выберите работу, которая нравится больше всех.',
            self::Judging => 'Голосование закрыто, считаем результаты.',
            self::Finished => 'Конкурс завершён, победители объявлены.',
        };
    }

    public function isArchive(): bool
    {
        return $this === self::Finished;
    }
}
