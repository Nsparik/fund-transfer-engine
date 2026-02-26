<?php

declare(strict_types=1);

namespace App\Shared\Application\Reconciliation;

use App\Shared\Application\DTO\ReconciliationResult;
use App\Shared\Application\Port\ReconciliationRepositoryInterface;

/**
 * Application service that orchestrates a full ledger balance reconciliation pass.
 *
 * Reads all accounts and their latest ledger snapshots via the
 * ReconciliationRepositoryInterface port, then maps each row to a
 * ReconciliationResult carrying a computed status and diff.
 *
 * ## Responsibilities
 *   - Delegate all I/O to the repository port (fully testable with a mock)
 *   - Produce one ReconciliationResult per account
 *   - Apply no business rule changes — this is a read-only diagnostic operation
 *
 * ## Callers
 *   ReconcileBalancesCommand (CLI) is the primary consumer.
 *   Any future monitoring endpoint or scheduled job can call reconcile() directly.
 */
final class ReconcileBalancesService
{
    public function __construct(
        private readonly ReconciliationRepositoryInterface $repository,
    ) {}

    /**
     * Run a full reconciliation pass over all accounts.
     *
     * @return list<ReconciliationResult>  One entry per account, ordered by account ID.
     */
    public function reconcile(): array
    {
        $rows = $this->repository->findAllForReconciliation();

        return array_map(
            static fn ($row) => new ReconciliationResult(
                accountId:             $row->accountId,
                currency:              $row->currency,
                accountBalance:        $row->accountBalance,
                ledgerBalance:         $row->ledgerBalance,
                ledgerComputedBalance: $row->ledgerComputedBalance,
            ),
            $rows,
        );
    }

    /**
     * Stream reconciliation results in memory-bounded pages.
     *
     * Yields one ReconciliationResult at a time, fetching rows from the DB in
     * pages of $batchSize.  Safe to use on very large account tables without
     * exhausting PHP memory.
     *
     * @param int $batchSize  Rows per DB round-trip (default 500)
     * @return \Generator<int, ReconciliationResult>
     */
    public function reconcileBatched(int $batchSize = 500): \Generator
    {
        $offset = 0;

        do {
            $rows = $this->repository->findForReconciliationBatch($offset, $batchSize);

            foreach ($rows as $row) {
                yield new ReconciliationResult(
                    accountId:             $row->accountId,
                    currency:              $row->currency,
                    accountBalance:        $row->accountBalance,
                    ledgerBalance:         $row->ledgerBalance,
                    ledgerComputedBalance: $row->ledgerComputedBalance,
                );
            }

            $offset += $batchSize;
        } while (count($rows) === $batchSize);
    }
}
