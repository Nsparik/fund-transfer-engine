<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Reconciliation;

use App\Shared\Application\DTO\ReconciliationRow;
use App\Shared\Application\Port\ReconciliationRepositoryInterface;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed implementation of ReconciliationRepositoryInterface.
 *
 * ## Query strategy
 *
 *   A LEFT JOIN correlated subquery is used to retrieve the latest
 *   balance_after_minor_units per account in a single round-trip.
 *
 *   The subquery uses a WHERE + ORDER BY + LIMIT 1 pattern which MySQL resolves
 *   with a single index seek on idx_ledger_account_occurred (account_id, occurred_at):
 *
 *     LEFT JOIN LATERAL (
 *         SELECT balance_after_minor_units
 *         FROM   ledger_entries
 *         WHERE  account_id = a.id
 *         ORDER  BY occurred_at DESC, id DESC
 *         LIMIT  1
 *     ) le ON TRUE
 *
 *   LATERAL is supported in MySQL 8.0.14+ (our minimum is 8.0).
 *   For each account row the subquery is a single index seek on
 *   idx_ledger_account_occurred — O(log N) per account, not O(N) full scan.
 *   The window-function approach (ROW_NUMBER + PARTITION BY) forces a full
 *   scan of the entire ledger_entries table regardless of how many accounts
 *   are in the batch.
 *
 *   LEFT JOIN — accounts with no ledger entries get ledger_balance = NULL.
 *
 * ## No domain types
 *   This class is in Shared Infrastructure and deliberately imports NO Account
 *   or Ledger domain classes.  It maps raw SQL rows to ReconciliationRow DTOs.
 *
 * ## Read-only
 *   No INSERT / UPDATE / DELETE.  No FOR UPDATE / LOCK IN SHARE MODE.
 *   Safe to run concurrently with live traffic.
 */
final class DbalReconciliationRepository implements ReconciliationRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findAllForReconciliation(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                a.id                         AS account_id,
                a.currency,
                a.balance_minor_units        AS account_balance,
                le.balance_after_minor_units AS ledger_balance,
                lc.computed_balance          AS ledger_computed_balance
            FROM accounts a
            LEFT JOIN LATERAL (
                SELECT balance_after_minor_units
                FROM   ledger_entries
                WHERE  account_id = a.id
                ORDER  BY occurred_at DESC, id DESC
                LIMIT  1
            ) le ON TRUE
            LEFT JOIN LATERAL (
                -- Compute SUM(credits) − SUM(debits) of amount_minor_units
                -- to detect corrupt intermediate entries that pass the snapshot check.
                SELECT SUM(
                    CASE WHEN entry_type = 'credit'
                         THEN  amount_minor_units
                         ELSE -amount_minor_units
                    END
                ) AS computed_balance
                FROM ledger_entries
                WHERE account_id = a.id
            ) lc ON TRUE
            ORDER BY a.id
            SQL,
        );

        return array_map(
            static fn (array $row) => new ReconciliationRow(
                accountId:             (string) $row['account_id'],
                currency:              (string) $row['currency'],
                accountBalance:        (int) $row['account_balance'],
                ledgerBalance:         $row['ledger_balance'] !== null ? (int) $row['ledger_balance'] : null,
                ledgerComputedBalance: $row['ledger_computed_balance'] !== null ? (int) $row['ledger_computed_balance'] : null,
            ),
            $rows,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findForReconciliationBatch(int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                SELECT
                    a.id                         AS account_id,
                    a.currency,
                    a.balance_minor_units        AS account_balance,
                    le.balance_after_minor_units AS ledger_balance,
                    lc.computed_balance          AS ledger_computed_balance
                FROM accounts a
                LEFT JOIN LATERAL (
                    SELECT balance_after_minor_units
                    FROM   ledger_entries
                    WHERE  account_id = a.id
                    ORDER  BY occurred_at DESC, id DESC
                    LIMIT  1
                ) le ON TRUE
                LEFT JOIN LATERAL (
                    SELECT SUM(
                        CASE WHEN entry_type = 'credit'
                             THEN  amount_minor_units
                             ELSE -amount_minor_units
                        END
                    ) AS computed_balance
                    FROM ledger_entries
                    WHERE account_id = a.id
                ) lc ON TRUE
                ORDER BY a.id
                LIMIT %d OFFSET %d
                SQL,
                $limit,
                $offset,
            ),
        );

        return array_map(
            static fn (array $row) => new ReconciliationRow(
                accountId:             (string) $row['account_id'],
                currency:              (string) $row['currency'],
                accountBalance:        (int) $row['account_balance'],
                ledgerBalance:         $row['ledger_balance'] !== null ? (int) $row['ledger_balance'] : null,
                ledgerComputedBalance: $row['ledger_computed_balance'] !== null ? (int) $row['ledger_computed_balance'] : null,
            ),
            $rows,
        );
    }
}
