<?php

declare(strict_types=1);

namespace App\Shared\UI\Cli;

use App\Shared\Infrastructure\Outbox\OutboxProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Polls the outbox_events table and dispatches unpublished domain events.
 *
 * ## Usage
 *
 *   # One-shot (for cron, every minute):
 *   php bin/console app:outbox:process --once
 *
 *   # Long-running daemon (for Supervisor):
 *   php bin/console app:outbox:process --sleep=1
 *
 *   # Custom batch size:
 *   php bin/console app:outbox:process --once --batch-size=50
 *
 * ## Graceful shutdown
 *   The daemon checks for SIGTERM/SIGINT between polls (via pcntl) when
 *   the extension is available. On platforms without pcntl the loop runs
 *   until the process is forcefully killed — safe because each poll
 *   is idempotent.
 */
#[AsCommand(
    name: 'app:outbox:process',
    description: 'Poll the outbox_events table and dispatch unpublished domain events.',
)]
final class ProcessOutboxCommand extends Command
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly OutboxProcessor $processor,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                name:        'batch-size',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Number of events to process per poll.',
                default:     100,
            )
            ->addOption(
                name:        'once',
                mode:        InputOption::VALUE_NONE,
                description: 'Process one batch then exit (use for cron jobs).',
            )
            ->addOption(
                name:        'sleep',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Seconds to sleep between polls when queue is empty (daemon mode).',
                default:     1,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');
        $once      = (bool) $input->getOption('once');
        $sleep     = (int) $input->getOption('sleep');

        $this->registerSignalHandlers();

        $io->title('Outbox Processor');

        if ($once) {
            $published = $this->processor->pollAndPublish($batchSize);
            $io->success(sprintf('Published %d event(s).', $published));
            return Command::SUCCESS;
        }

        // ── Daemon mode ───────────────────────────────────────────────────────
        $io->info(sprintf(
            'Running in daemon mode (batch-size=%d, sleep=%ds). Send SIGTERM to stop.',
            $batchSize,
            $sleep,
        ));

        while (!$this->shouldStop) {
            try {
                $published = $this->processor->pollAndPublish($batchSize);

                if ($published > 0) {
                    $io->writeln(sprintf(
                        '[%s] Published %d event(s).',
                        (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                        $published,
                    ));
                }
            } catch (\Throwable $e) {
                // Log and continue — a transient DB error should not crash the daemon.
                $this->logger->error('outbox.processor_poll_error', [
                    'error_class'   => $e::class,
                    'error_message' => $e->getMessage(),
                ]);
                $io->error(sprintf('Poll error: %s', $e->getMessage()));
            }

            if ($this->shouldStop) {
                break;
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            sleep($sleep);
        }

        $io->success('Outbox processor stopped gracefully.');
        return Command::SUCCESS;
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        // Enable asynchronous signal delivery so SIGTERM/SIGINT are processed
        // immediately rather than only at PHP function call boundaries.
        // Without this, a signal sent during sleep($sleep) would be ignored
        // until the sleep completes.
        pcntl_async_signals(true);

        $stop = function (): void {
            $this->shouldStop = true;
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT,  $stop);
    }
}
