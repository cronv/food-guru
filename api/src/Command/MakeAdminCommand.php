<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Первый администратор на боевом сервере. Пароль не задаётся: человек входит
 * по ссылке из письма на свою почту и там же придумывает пароль.
 */
#[AsCommand(name: 'food-guru:admin', description: 'Создать администратора или повысить существующий аккаунт')]
final class MakeAdminCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Адрес почты')
            ->addArgument('name', InputArgument::OPTIONAL, 'Имя, если аккаунта ещё нет', 'Администратор');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $output->writeln('<error>Некорректный адрес почты.</error>');

            return Command::FAILURE;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $id = $this->db->fetchOne('SELECT id FROM users WHERE email = ?', [$email]);

        if (false === $id) {
            $this->db->insert('users', [
                'name' => (string) $input->getArgument('name'),
                'email' => $email,
                'role' => 'admin',
                'is_active' => 1,
                // Почту подтвердит первый же вход по ссылке — она придёт именно на этот адрес.
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $output->writeln("Создан администратор {$email}");
        } else {
            $this->db->executeStatement(
                'UPDATE users SET role = ?, is_active = 1, email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?',
                ['admin', $now, $now, $id],
            );
            $output->writeln("Права администратора выданы: {$email}");
        }

        $output->writeln('Вход: страница админки → «По ссылке» → письмо придёт на этот адрес.');

        return Command::SUCCESS;
    }
}
