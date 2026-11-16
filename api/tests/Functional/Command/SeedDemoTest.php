<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\SeedDemoCommand;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Демо-данные в окружении test: первая загрузка и защита от повтора. */
final class SeedDemoTest extends ApiTestCase
{
    public function testSeedsOnceAndDoesNotDuplicateOnRepeat(): void
    {
        $tester = new CommandTester(static::getContainer()->get(SeedDemoCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Демо-данные загружены', $tester->getDisplay());
        self::assertSame(1, $this->adminCount());
        self::assertGreaterThan(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM recipes'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Демо уже загружено', $tester->getDisplay());
        self::assertSame(1, $this->adminCount());
    }

    private function adminCount(): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM users WHERE email = 'admin@food-guru.local'");
    }
}
