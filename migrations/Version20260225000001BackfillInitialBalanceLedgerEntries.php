<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Backfill initial-balance ledger entries for seeded accounts.
 *
 * ## Problem
 *   When an account is created via POST /accounts with initialBalanceMinorUnits > 0,
 *   the opening balance is stored directly in accounts.balance_minor_units.
 *   No ledger entry is written for this initial credit, so the ledger cannot
 *   reconstruct the full financial history of those accounts from day one.
 *   ReconcileBalancesService detects and reports this gap.
 *
 * ## What this migration does
 *   Inserts one CREDIT ledger entry per account that:
 *     - Has a non-zero balance
 *     - Has NO prior ledger entries (balance is entirely from initial seeding)
 *
 *   Accounts that already have ledger entries (from completed transfers) are
 *   excluded because their current balance may differ from the original seeded
 *   balance.  Inserting a "bootstrap" entry for them would create an incorrect
 *   balance_after snapshot.
 *
 * ## Idempotency
 *   INSERT IGNORE against the UNIQUE KEY uidx_ledger_account_transfer_type
 *   (account_id, transfer_id, entry_type) ensures this migration is safe to
 *   re-run.  All bootstrap entries use the fixed system transfer reference
 *   SYSTEM_BOOTSTRAP_TRANSFER_ID, so any re-run attempt will fire the UNIQUE
 *   constraint and IGNORE the duplicate silently.
 *
 * ## Column values
 *
 *   id                        — LOWER(UUID()) — non-deterministic but safe because
 *                               idempotency is enforced by the UNIQUE constraint
 *                               on (account_id, transfer_id, entry_type), not by id
 *   account_id                — the account receiving the bootstrap credit
 *   counterparty_account_id   — fixed system UUID (no real counterparty)
 *   transfer_id               — fixed system UUID (no real transfer)
 *   entry_type                — 'credit'   (funds entering the account)
 *   transfer_type             — 'bootstrap' (identifies the entry source)
 *   amount_minor_units        — account's current balance (proxy for initial balance)
 *   currency                  — account's currency
 *   balance_after_minor_units — same as amount (initial state: balance = opening credit)
 *   occurred_at               — account's created_at (closest proxy to opening date)
 *   created_at                — NOW(6)
 *
 * ## Rollback
 *   down() deletes all rows with transfer_id = SYSTEM_BOOTSTRAP_TRANSFER_ID
 *   and transfer_type = 'bootstrap'.  This is safe because those rows are
 *   synthetic migration artefacts, not derived from real financial events.
 *
 * ## Constraints respected
 *   - Does NOT modify accounts.balance_minor_units
 *   - Does NOT modify existing ledger_entries rows
 *   - Does NOT modify any other table
 *   - Respects uidx_ledger_account_transfer_type uniqueness constraint
 *   - Follows append-only ledger principle (INSERT only, no UPDATE/DELETE on live data)
 */
final class Version20260225000001BackfillInitialBalanceLedgerEntries extends AbstractMigration
{
    /**
     * Fixed UUID used as the transfer_id for all bootstrap ledger entries.
     * Chosen as a recognisable constant so that:
     *   a) The entries are easily identifiable in queries.
     *   b) The UNIQUE constraint (account_id, transfer_id, entry_type) prevents
     *      duplicates on re-run (same account_id + same transfer_id + 'credit').
     *   c) down() can delete exactly these rows without touching real entries.
     */
    private const SYSTEM_BOOTSTRAP_TRANSFER_ID   = '00000000-0000-7000-8000-000000000001';
    private const SYSTEM_COUNTERPARTY_ACCOUNT_ID = '00000000-0000-7000-8000-000000000000';

    public function getDescription(): string
    {
        return 'Backfill initial-balance ledger entries for seeded accounts with no prior ledger history';
    }

    public function up(): void
    {
        // Insert one bootstrap CREDIT for every account that:
        //   1. Has a non-zero balance (balance_minor_units > 0)
        //   2. Has no existing ledger entries at all (NOT EXISTS sub-query)
        //
        // Condition 2 ensures we do not create a bootstrap entry for accounts
        // that already have ledger history from completed transfers, where the
        // current balance would be an incorrect proxy for the initial balance.
        //
        // INSERT IGNORE means the statement is safe to run multiple times:
        // the UNIQUE KEY uidx_ledger_account_transfer_type fires on the second
        // run and skips the row silently.
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT IGNORE INTO ledger_entries (
                id,
                account_id,
                counterparty_account_id,
                transfer_id,
                entry_type,
                transfer_type,
                amount_minor_units,
                currency,
                balance_after_minor_units,
                occurred_at,
                created_at
            )
            SELECT
                LOWER(UUID()),
                a.id,
                :counterparty_id,
                :transfer_id,
                'credit',
                'bootstrap',
                a.balance_minor_units,
                a.currency,
                a.balance_minor_units,
                a.created_at,
                NOW(6)
            FROM accounts a
            WHERE a.balance_minor_units > 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM ledger_entries le
                  WHERE le.account_id = a.id
              )
            SQL,
            [
                'counterparty_id' => self::SYSTEM_COUNTERPARTY_ACCOUNT_ID,
                'transfer_id'     => self::SYSTEM_BOOTSTRAP_TRANSFER_ID,
            ],
        );
    }

    public function down(): void
    {
        // Remove only the bootstrap entries created by this migration.
        // Real transfer ledger entries are never touched.
        $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM ledger_entries
            WHERE transfer_id  = :transfer_id
              AND transfer_type = 'bootstrap'
            SQL,
            ['transfer_id' => self::SYSTEM_BOOTSTRAP_TRANSFER_ID],
        );
    }
}
