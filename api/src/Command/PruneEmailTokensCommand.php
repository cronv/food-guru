<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Протухшие токены не нужны ни для чего: чистим раз в сутки, cron запускает команду. */
#[AsCommand(name: 'food-guru:prune-email-tokens', description: 'Удалить токены из писем, истёкшие больше 7 дней назад')]
final class PruneEmailTokensCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cutoff = $this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s');
        $deleted = $this->db->executeStatement('DELETE FROM email_tokens WHERE expires_at < ?', [$cutoff]);

        $output->writeln("Удалено токенов: {$deleted}");

        return Command::SUCCESS;
    }
}
