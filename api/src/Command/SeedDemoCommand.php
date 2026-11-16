<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\DemoSeeder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Демо-данные для витрины и разработки: рецепты, читатели, книги, магазин, конкурсы, журнал.
 * Повторный запуск ничего не дублирует: признак загрузки — администратор с демо-почтой.
 */
#[AsCommand(name: 'food-guru:seed-demo', description: 'Загрузить демо-данные для витрины и разработки')]
final class SeedDemoCommand extends Command
{
    private const ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private readonly Connection $db,
        private readonly DemoSeeder $seeder,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Разрешить запуск вне dev и test (только если это осознанно)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true) && !$input->getOption('force')) {
            $output->writeln("<error>Окружение {$this->environment}: демо-данные не загружаются.</error>");
            $output->writeln('Запускайте команду с --force, если это осознанно. На боевой базе это добавит демо-аккаунты с публичными адресами.');

            return Command::FAILURE;
        }

        if (false !== $this->db->fetchOne('SELECT id FROM users WHERE email = ?', [DemoSeeder::ADMIN_EMAIL])) {
            $output->writeln('Демо уже загружено: администратор '.DemoSeeder::ADMIN_EMAIL.' есть в базе. Повторная загрузка пропущена.');

            return Command::SUCCESS;
        }

        $this->seeder->seed();
        $output->writeln('Демо-данные загружены.');

        return Command::SUCCESS;
    }
}
