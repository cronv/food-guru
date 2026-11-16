<?php

declare(strict_types=1);

namespace App\Enum;

/** Фаза конкурса — считается из дат, в базе не хранится. Как App\Enums\ContestPhase. */
enum ContestPhase: string
{
    case Announced = 'announced';
    case Submissions = 'submissions';
    case Voting = 'voting';
    case Judging = 'judging';
    case Finished = 'finished';

    /**
     * Как Contest::phase(): от последнего события к первому, «наступило» — значит
     * дата не позже текущего момента.
     *
     * @param array<string, mixed> $contest строка contests
     */
    public static function of(array $contest, \DateTimeImmutable $now): self
    {
        $reached = static function (string $column) use ($contest, $now): bool {
            return null !== $contest[$column] && new \DateTimeImmutable((string) $contest[$column], new \DateTimeZone('UTC')) <= $now;
        };

        return match (true) {
            $reached('results_at') => self::Finished,
            $reached('voting_close_at') => self::Judging,
            $reached('voting_open_at') => self::Voting,
            $reached('submissions_close_at') => self::Judging,
            $reached('submissions_open_at') => self::Submissions,
            default => self::Announced,
        };
    }

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

    /** Какая дата — ближайший рубеж в этой фазе. */
    public function deadlineColumn(): ?string
    {
        return match ($this) {
            self::Announced => 'submissions_open_at',
            self::Submissions => 'submissions_close_at',
            self::Voting => 'voting_close_at',
            self::Judging => 'results_at',
            self::Finished => null,
        };
    }
}
