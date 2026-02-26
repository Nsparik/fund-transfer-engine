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
    name: 'app:migrations:rollback',
    description: 'Roll back the last applied database migration.',
)]
final class RollbackCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Database Migrations — rollback');

        $version = $this->runner->rollback();

        if ($version === null) {
            $io->warning('Nothing to roll back. No migrations have been applied.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Rolled back migration: %s', $version));

        return Command::SUCCESS;
    }
}
