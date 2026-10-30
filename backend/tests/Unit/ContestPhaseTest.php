<?php

namespace Tests\Unit;

use App\Enums\ContestPhase;
use App\Models\Contest;
use Tests\TestCase;

/**
 * Фаза конкурса считается из дат, а не хранится. Это главное решение
 * в разделе конкурсов, и именно его легче всего сломать правкой условий.
 *
 * Наследуется от Tests\TestCase, а не от голого PHPUnit: каст дат в модели
 * берёт формат у подключения к базе, без приложения его нет.
 */
class ContestPhaseTest extends TestCase
{
    private function contest(array $dates): Contest
    {
        $contest = new Contest;
        $contest->forceFill($dates);

        return $contest;
    }

    public function test_без_дат_конкурс_считается_анонсом(): void
    {
        $this->assertSame(ContestPhase::Announced, $this->contest([])->phase());
    }

    public function test_до_открытия_приёма_работ_это_анонс(): void
    {
        $contest = $this->contest(['submissions_open_at' => now()->addDay()]);

        $this->assertSame(ContestPhase::Announced, $contest->phase());
    }

    public function test_между_открытием_и_закрытием_идёт_приём_работ(): void
    {
        $contest = $this->contest([
            'submissions_open_at' => now()->subDay(),
            'submissions_close_at' => now()->addDay(),
        ]);

        $this->assertSame(ContestPhase::Submissions, $contest->phase());
        $this->assertTrue($contest->acceptsEntries() === false, 'Неопубликованный конкурс работ не принимает');
    }

    public function test_после_закрытия_приёма_но_до_голосования_подводятся_итоги(): void
    {
        $contest = $this->contest([
            'submissions_open_at' => now()->subDays(5),
            'submissions_close_at' => now()->subDay(),
            'voting_open_at' => now()->addDay(),
        ]);

        $this->assertSame(ContestPhase::Judging, $contest->phase());
    }

    public function test_в_окне_голосования_идёт_голосование(): void
    {
        $contest = $this->contest([
            'submissions_close_at' => now()->subDays(2),
            'voting_open_at' => now()->subDay(),
            'voting_close_at' => now()->addDay(),
        ]);

        $this->assertSame(ContestPhase::Voting, $contest->phase());
    }

    public function test_после_итогов_конкурс_завершён_и_уходит_в_архив(): void
    {
        $contest = $this->contest([
            'voting_close_at' => now()->subDays(2),
            'results_at' => now()->subDay(),
        ]);

        $this->assertSame(ContestPhase::Finished, $contest->phase());
        $this->assertTrue($contest->phase()->isArchive());
    }

    public function test_дедлайн_показывает_ближайшую_дату_текущей_фазы(): void
    {
        $close = now()->addDays(3);

        $contest = $this->contest([
            'submissions_open_at' => now()->subDay(),
            'submissions_close_at' => $close,
        ]);

        $this->assertSame($close->toDateTimeString(), $contest->deadlineAt()?->toDateTimeString());
    }
}
