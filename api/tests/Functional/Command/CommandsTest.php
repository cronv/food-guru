<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\MakeAdminCommand;
use App\Command\PruneEmailTokensCommand;
use App\Enum\UserRole;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Команды, которые деплой вызывает на сервере: первый администратор и очистка токенов. */
final class CommandsTest extends ApiTestCase
{
    public function testMakeAdminCreatesAdminWithoutPassword(): void
    {
        $tester = new CommandTester(new MakeAdminCommand($this->db(), new MockClock('2026-11-10 10:00:00')));

        $status = $tester->execute(['email' => 'Chef@Example.com', 'name' => 'Ирина']);

        self::assertSame(Command::SUCCESS, $status);
        $row = $this->db()->fetchAssociative('SELECT name, role, is_active, password FROM users WHERE email = ?', ['chef@example.com']);
        self::assertSame(['name' => 'Ирина', 'role' => 'admin', 'is_active' => 1, 'password' => null], $row);
    }

    public function testMakeAdminPromotesExistingUser(): void
    {
        $user = $this->user(UserRole::User);
        $tester = new CommandTester(new MakeAdminCommand($this->db(), new MockClock('2026-11-10 10:00:00')));

        $status = $tester->execute(['email' => $user->getEmail()]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('admin', $this->db()->fetchOne('SELECT role FROM users WHERE id = ?', [$user->getId()]));
    }

    public function testMakeAdminRejectsInvalidEmail(): void
    {
        $tester = new CommandTester(new MakeAdminCommand($this->db(), new MockClock('2026-11-10 10:00:00')));

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'не-почта']));
    }

    public function testPruneDeletesOnlyTokensExpiredMoreThanAWeekAgo(): void
    {
        $user = $this->user();
        $this->token((int) $user->getId(), 'old', '2026-10-25 10:00:00');
        $this->token((int) $user->getId(), 'recent', '2026-11-09 10:00:00');

        $tester = new CommandTester(new PruneEmailTokensCommand($this->db(), new MockClock('2026-11-10 10:00:00')));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM email_tokens WHERE token_hash = 'old'"));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM email_tokens WHERE token_hash = 'recent'"));
    }

    private function token(int $userId, string $hash, string $expiresAt): void
    {
        $this->db()->insert('email_tokens', [
            'user_id' => $userId,
            'token_hash' => $hash,
            'type' => 'login',
            'email' => 'x@example.com',
            'expires_at' => $expiresAt,
            'created_at' => $expiresAt,
        ]);
    }
}
