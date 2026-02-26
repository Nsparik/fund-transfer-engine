<?php

declare(strict_types=1);

namespace App\Shared\UI\Cli;

use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command that bulk-resets dead-lettered outbox events so they will be
 * retried on the next OutboxProcessor poll cycle.
 *
 * ## Purpose
 *   Dead-lettered events are outbox rows where attempt_count >= MAX_ATTEMPTS
 *   and published_at IS NULL.  They will never be retried automatically.
 *   This command resets attempt_count = 0 and last_error = NULL in bulk so
 *   operators can recover from transient downstream outages without manual
 *   SQL intervention.
 *
 * ## Usage
 *   bin/console app:outbox:reset-dead-letters
 *   bin/console app:outbox:reset-dead-letters --max-attempts=3
 *   bin/console app:outbox:reset-dead-letters --dry-run
 *
 * ## Options
 *   --max-attempts=N  Events with attempt_count >= N are considered dead-
 *                     lettered (default: 5, matching OutboxProcessor's limit).
 *   --dry-run         Count matching rows and print the count without mutating
 *                     any data.  Safe to run at any time on production.
 *
 * ## Exit codes
 *   0 — success (including zero events reset)
 *   1 — unexpected error
 *
 * ## Logging
 *   INFO  — one line summarising how many events were reset (or would be reset)
 */
#[AsCommand(
    name:        'app:outbox:reset-dead-letters',
    description: 'Bulk-reset dead-lettered outbox events so they are retried on the next poll',
)]
final class ResetOutboxDeadLettersCommand extends Command
{
    public function __construct(
        private readonly OutboxRepositoryInterface $outboxRepository,
        private readonly LoggerInterface           $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'max-attempts',
                null,
                InputOption::VALUE_REQUIRED,
                'Events with attempt_count >= this value are considered dead-lettered',
                5,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Count matching events without resetting them (no DB writes)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $maxAttempts = (int) $input->getOption('max-attempts');
        $dryRun      = (bool) $input->getOption('dry-run');

        if ($maxAttempts < 1) {
            $io->error('--max-attempts must be >= 1.');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $events = $this->outboxRepository->findDeadLettered(PHP_INT_MAX);
            $count  = count(array_filter(
                $events,
                static fn ($e) => $e->attemptCount >= $maxAttempts,
            ));

            $io->note(sprintf(
                '[DRY-RUN] %d dead-lettered event(s) with attempt_count >= %d would be reset.',
                $count,
                $maxAttempts,
            ));

            $this->logger->info('outbox.reset_dead_letters.dry_run', [
                'max_attempts'  => $maxAttempts,
                'would_reset'   => $count,
            ]);

            return Command::SUCCESS;
        }

        $affected = $this->outboxRepository->resetDeadLetters($maxAttempts);

        if ($affected === 0) {
            $io->success(sprintf(
                'No dead-lettered events found with attempt_count >= %d. Nothing to reset.',
                $maxAttempts,
            ));
        } else {
            $io->success(sprintf(
                'Reset %d dead-lettered event(s) with attempt_count >= %d. '
                . 'They will be retried on the next OutboxProcessor poll.',
                $affected,
                $maxAttempts,
            ));
        }

        $this->logger->info('outbox.reset_dead_letters', [
            'max_attempts' => $maxAttempts,
            'affected'     => $affected,
        ]);

        return Command::SUCCESS;
    }
}
