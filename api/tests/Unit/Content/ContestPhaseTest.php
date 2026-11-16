<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Enum\ContestPhase;
use App\Enum\JournalPeriod;
use PHPUnit\Framework\TestCase;

/** Фаза конкурса из дат и подпись периода журнала. */
final class ContestPhaseTest extends TestCase
{
    /** @return array<string, mixed> */
    private function contest(string ...$reached): array
    {
        $columns = ['submissions_open_at', 'submissions_close_at', 'voting_open_at', 'voting_close_at', 'results_at'];
        $row = array_fill_keys($columns, '2030-01-01 00:00:00');
        foreach ($reached as $column) {
            $row[$column] = '2020-01-01 00:00:00';
        }

        return $row;
    }

    public function testAllFivePhasesFromDates(): void
    {
        $now = new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC'));

        self::assertSame(ContestPhase::Announced, ContestPhase::of($this->contest(), $now));
        self::assertSame(ContestPhase::Submissions, ContestPhase::of($this->contest('submissions_open_at'), $now));
        self::assertSame(ContestPhase::Judging, ContestPhase::of($this->contest('submissions_open_at', 'submissions_close_at'), $now));
        self::assertSame(ContestPhase::Voting, ContestPhase::of($this->contest('submissions_open_at', 'submissions_close_at', 'voting_open_at'), $now));
        self::assertSame(ContestPhase::Finished, ContestPhase::of($this->contest('submissions_open_at', 'submissions_close_at', 'voting_open_at', 'voting_close_at', 'results_at'), $now));
    }

    public function testDateExactlyNowCountsAsReached(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 12:00:00', new \DateTimeZone('UTC'));
        $row = $this->contest();
        $row['submissions_open_at'] = '2026-06-01 12:00:00';

        self::assertSame(ContestPhase::Submissions, ContestPhase::of($row, $now));
    }

    public function testMissingDatesAreNotReached(): void
    {
        $row = array_fill_keys(['submissions_open_at', 'submissions_close_at', 'voting_open_at', 'voting_close_at', 'results_at'], null);

        self::assertSame(ContestPhase::Announced, ContestPhase::of($row, new \DateTimeImmutable()));
    }

    public function testJournalPeriodLabels(): void
    {
        $utc = new \DateTimeZone('UTC');

        self::assertSame('Январь 2026', JournalPeriod::Month->describe(new \DateTimeImmutable('2026-01-15', $utc)));
        self::assertSame('12–18 января 2026', JournalPeriod::Week->describe(new \DateTimeImmutable('2026-01-14', $utc)));
        // Неделя на стыке лет: 29 декабря 2025 — 4 января 2026.
        self::assertSame('29 декабря – 4 января 2026', JournalPeriod::Week->describe(new \DateTimeImmutable('2026-01-01', $utc)));
    }
}
