<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\DTO\ReconciliationRow;

/**
 * ReconciliationRepositoryInterface — Shared read-only cross-module port.
 *
 * Abstracts the query that joins the accounts table with the latest
 * ledger snapshot per account.  The implementation lives in
 * Shared\Infrastructure\Reconciliation\ and is free to use window functions,
 * CTEs, or any other DB-level optimisation.
 *
 * ## Why Shared (not Account or Ledger)?
 *   Reconciliation is inherently cross-module: it reads from both the Account
 *   and Ledger bounded contexts.  Placing it here keeps both domain modules
 *   free of cross-context imports while giving the CLI command a clean,
 *   mockable port to test against.
 *
 * ## Read-only contract
 *   Implementations MUST NOT acquire row locks or start transactions.
 *   This query is a purely analytical read and must not interfere with
 *   in-flight transfers.
 */
interface ReconciliationRepositoryInterface
{
    /**
     * Return one ReconciliationRow per account, pairing each account's
     * current live balance with the balance_after_minor_units of its most
     * recent ledger entry.
     *
     * ledgerBalance is null when the account has no ledger entries.
     *
     * Results are ordered by account_id (stable ordering for deterministic CLI output).
     *
     * @return list<ReconciliationRow>
     */
    public function findAllForReconciliation(): array;

    /**
     * Paginated variant — returns a single page of ReconciliationRows.
     *
     * Intended for large datasets where loading every account in one query
     * would blow PHP memory limits or starve the connection pool.
     *
     * @param int $offset  Number of rows to skip (0-based)
     * @param int $limit   Maximum rows to return
     * @return list<ReconciliationRow>
     */
    public function findForReconciliationBatch(int $offset, int $limit): array;
}
