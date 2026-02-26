<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Migration\Command;

use App\Shared\Infrastructure\Migration\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrations:migrate',
    description: 'Apply all pending database migrations.',
)]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Database Migrations — migrate');

        $applied = $this->runner->migrate();

        if (empty($applied)) {
            $io->success('Nothing to migrate. Database is up to date.');
            return Command::SUCCESS;
        }

        foreach ($applied as $version) {
            $io->writeln(sprintf('  <info>✓</info> Applied: <comment>%s</comment>', $version));
        }

        $io->success(sprintf('%d migration(s) applied successfully.', count($applied)));

        return Command::SUCCESS;
    }
}
