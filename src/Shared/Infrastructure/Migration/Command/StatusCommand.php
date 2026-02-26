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
    name: 'app:migrations:status',
    description: 'List all migrations and their applied/pending status.',
)]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Database Migrations — status');

        $rows = [];
        foreach ($this->runner->getStatus() as $m) {
            $statusLabel = $m['status'] === 'applied'
                ? sprintf('<info>%s</info>', $m['status'])
                : sprintf('<comment>%s</comment>', $m['status']);

            $rows[] = [
                $m['version'],
                $statusLabel,
                $m['applied_at'] ?? '—',
                $m['description'],
            ];
        }

        if (empty($rows)) {
            $io->note('No migrations found in the migrations/ directory.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Version', 'Status', 'Applied At', 'Description'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
