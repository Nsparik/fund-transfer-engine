<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Create the `ledger_entries` table.
 *
 * ## Purpose
 *   Immutable, append-only double-entry ledger.  Every completed transfer
 *   (and every reversal) produces exactly TWO rows: one DEBIT on the source
 *   account, one CREDIT on the destination account.
 *
 * ## Column rationale
 *
 *   id                        CHAR(36) UUIDv7 — time-ordered for B-tree efficiency
 *   account_id                CHAR(36) — which account this leg belongs to
 *   counterparty_account_id   CHAR(36) — the other account; stored denormalised so
 *                                         statement consumers need no join to transfers
 *   transfer_id               CHAR(36) — FK reference to transfers.id
 *   entry_type                VARCHAR(10) — 'debit' | 'credit'
 *   transfer_type             VARCHAR(20) — 'transfer' | 'reversal'
 *   amount_minor_units        BIGINT UNSIGNED — always > 0; UNSIGNED prevents negatives
 *   currency                  CHAR(3)  — ISO 4217 code, matches account currency
 *   balance_after_minor_units BIGINT UNSIGNED — running balance AFTER this entry
 *                                               stored denormalised for O(1) balance-at-date
 *   occurred_at               DATETIME(6) — UTC; when the transfer completed
 *   created_at                DATETIME(6) — UTC; when this row was written
 *
 * ## Index strategy
 *   PRIMARY KEY (id)
 *     — UUID v7 primary key; time-ordered minimises page splits on high-volume inserts
 *
 *   idx_ledger_account_occurred (account_id, occurred_at)
 *     — The primary query pattern: "all entries for account X between dates A and B"
 *     — ASC composite index; MySQL 8.0 backward index scan satisfies ORDER BY occurred_at DESC
 *       efficiently without a filesort (index is traversed in reverse)
 *
 *   idx_ledger_transfer (transfer_id)
 *     — Transfer reconciliation: find both legs of a transfer in one index scan
 *     — Also used by the reconciliation CLI
 *
 *   uidx_ledger_account_transfer_type (account_id, transfer_id, entry_type) UNIQUE
 *     — CRITICAL idempotency guard: prevents duplicate ledger entries if the
 *       handler is ever retried due to a bug, crash, or at-least-once delivery.
 *     — INSERT IGNORE against this constraint is the idempotency mechanism.
 *     — A transfer can have exactly one DEBIT and one CREDIT per account.
 *
 * ## MySQL 8.0 strict-mode compliance
 *   - All NOT NULL columns with no defaults that require values
 *   - BIGINT UNSIGNED blocks negative values at storage level
 *   - DATETIME(6) for microsecond precision UTC timestamps
 */
class Version20260224000006CreateLedgerEntriesTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ledger_entries table — immutable double-entry ledger with idempotency constraint';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ledger_entries (
                id                        CHAR(36)        NOT NULL,
                account_id                CHAR(36)        NOT NULL,
                counterparty_account_id   CHAR(36)        NOT NULL,
                transfer_id               CHAR(36)        NOT NULL,
                entry_type                VARCHAR(10)     NOT NULL,
                transfer_type             VARCHAR(20)     NOT NULL,
                amount_minor_units        BIGINT UNSIGNED NOT NULL,
                currency                  CHAR(3)         NOT NULL,
                balance_after_minor_units BIGINT UNSIGNED NOT NULL,
                occurred_at               DATETIME(6)     NOT NULL,
                created_at                DATETIME(6)     NOT NULL,

                PRIMARY KEY (id),

                INDEX idx_ledger_account_occurred (account_id, occurred_at),

                INDEX idx_ledger_transfer (transfer_id),

                UNIQUE KEY uidx_ledger_account_transfer_type
                    (account_id, transfer_id, entry_type)

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Immutable double-entry ledger — one row per account per transfer leg'
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ledger_entries');
    }
}
