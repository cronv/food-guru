<?php

namespace Tests\Unit;

use App\Enums\JournalPeriod;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class JournalPeriodTest extends TestCase
{
    public function test_месяц_приводится_к_первому_числу(): void
    {
        $date = JournalPeriod::Month->normalize(Carbon::parse('2026-01-17'));

        $this->assertSame('2026-01-01', $date->toDateString());
    }

    public function test_неделя_приводится_к_понедельнику(): void
    {
        // 17 января 2026 — суббота.
        $date = JournalPeriod::Week->normalize(Carbon::parse('2026-01-17'));

        $this->assertSame('2026-01-12', $date->toDateString());
        $this->assertSame(1, $date->dayOfWeekIso);
    }

    public function test_подпись_месяца_в_именительном_падеже(): void
    {
        $this->assertSame('Январь 2026', JournalPeriod::Month->describe(Carbon::parse('2026-01-09')));
    }

    public function test_подпись_недели_это_диапазон_в_родительном_падеже(): void
    {
        $this->assertSame('12–18 января 2026', JournalPeriod::Week->describe(Carbon::parse('2026-01-14')));
    }

    public function test_неделя_на_стыке_месяцев_называет_оба(): void
    {
        // Неделя с 26 января по 1 февраля 2026.
        $label = JournalPeriod::Week->describe(Carbon::parse('2026-01-28'));

        $this->assertStringContainsString('января', $label);
        $this->assertStringContainsString('февраля', $label);
    }
}
