<?php

declare(strict_types=1);

namespace App\Shared\UI\Cli;

use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator tool to re-queue dead-lettered outbox events.
 *
 * ## Why this command exists
 *   OutboxProcessor permanently skips events whose attempt_count >= MAX_ATTEMPTS.
 *   The docblock for OutboxProcessor documents the manual recovery procedure:
 *   "fix the downstream consumer, then set published_at = NULL and
 *   attempt_count = 0 to re-queue."
 *
 *   Running raw SQL against a production database under incident pressure is a
 *   data-integrity risk.  This command encodes that procedure safely:
 *   - Shows what would be re-queued (dry-run by default)
 *   - Validates that only dead-lettered events (published_at IS NULL,
 *     attempt_count >= 5) are touched
 *   - Logs every re-queue action with full context for the audit trail
 *   - Requires --execute to make any changes (safe-by-default)
 *
 * ## Usage
 *
 *   # Preview dead-lettered events (no changes):
 *   php bin/console app:outbox:requeue-dead-letters
 *
 *   # Re-queue all dead-lettered events:
 *   php bin/console app:outbox:requeue-dead-letters --execute
 *
 *   # Re-queue a specific event by ID:
 *   php bin/console app:outbox:requeue-dead-letters --execute --id=<uuid>
 */
#[AsCommand(
    name: 'app:outbox:requeue-dead-letters',
    description: 'List and optionally re-queue dead-lettered outbox events (attempt_count >= 5, published_at IS NULL).',
)]
final class RequeueOutboxDeadLetterCommand extends Command
{
    public function __construct(
        private readonly OutboxRepositoryInterface $outbox,
        private readonly LoggerInterface           $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                name:        'execute',
                mode:        InputOption::VALUE_NONE,
                description: 'Actually reset dead-lettered events for re-processing. Omit to do a dry-run.',
            )
            ->addOption(
                name:        'id',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Limit to a single event UUID. When omitted all dead-lettered events are processed.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');
        $singleId = $input->getOption('id');

        $events = $this->outbox->findDeadLettered(limit: 1000, id: is_string($singleId) ? $singleId : null);

        if (count($events) === 0) {
            $io->success('No dead-lettered outbox events found.');

            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Event type', 'Aggregate', 'Attempts', 'Last error (truncated)'],
            array_map(static fn ($e) => [
                $e->id->toString(),
                $e->eventType,
                $e->aggregateType . ':' . $e->aggregateId,
                $e->attemptCount,
                $e->lastError !== null ? mb_strimwidth($e->lastError, 0, 80, '…') : '—',
            ], $events),
        );

        if (!$execute) {
            $io->warning(sprintf(
                'Dry-run: %d event(s) listed above would be re-queued. Add --execute to apply.',
                count($events),
            ));

            return Command::SUCCESS;
        }

        $io->section(sprintf('Re-queuing %d event(s)…', count($events)));

        $succeeded = 0;
        $failed    = 0;

        foreach ($events as $event) {
            try {
                $this->outbox->resetForRequeue($event->id);

                $this->logger->info('outbox.requeue_dead_letter', [
                    'outbox_event_id' => $event->id->toString(),
                    'event_type'      => $event->eventType,
                    'aggregate_id'    => $event->aggregateId,
                    'prior_attempts'  => $event->attemptCount,
                    'last_error'      => $event->lastError,
                ]);

                ++$succeeded;
            } catch (\Throwable $e) {
                $io->error(sprintf(
                    'Failed to reset event %s: %s',
                    $event->id->toString(),
                    $e->getMessage(),
                ));
                ++$failed;
            }
        }

        if ($failed === 0) {
            $io->success(sprintf('%d event(s) re-queued successfully.', $succeeded));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d re-queued, %d failed.', $succeeded, $failed));

        return Command::FAILURE;
    }
}
