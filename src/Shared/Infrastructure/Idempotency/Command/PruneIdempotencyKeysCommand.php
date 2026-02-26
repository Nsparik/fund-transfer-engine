<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency\Command;

use App\Shared\Domain\Idempotency\IdempotencyRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prune expired idempotency key records.
 *
 * Records expire 24 hours after creation (see IdempotencyRecord::create()).
 * This command should be scheduled as a nightly cron job:
 *
 *   # crontab / Kubernetes CronJob
 *   0 2 * * *  docker compose exec php bin/console app:idempotency:prune
 *
 * Without regular pruning the idempotency_keys table will grow without bound
 * and eventually degrade query performance on the index scan used by findByKey().
 *
 * ## Security note
 *   Only rows where expires_at <= NOW() are deleted.  Live records (last 24 h)
 *   are always retained so in-flight retries never lose their cache entry.
 */
#[AsCommand(
    name: 'app:idempotency:prune',
    description: 'Delete expired idempotency key records (TTL: 24 hours).',
)]
final class PruneIdempotencyKeysCommand extends Command
{
    public function __construct(
        private readonly IdempotencyRepositoryInterface $idempotencyRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->idempotencyRepository->deleteExpired();

        if ($deleted === 0) {
            $io->success('No expired idempotency records found — nothing to prune.');
        } else {
            $io->success(sprintf('Pruned %d expired idempotency record(s).', $deleted));
        }

        return Command::SUCCESS;
    }
}
