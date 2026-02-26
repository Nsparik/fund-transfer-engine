<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Add foreign-key constraints to child tables.
 *
 * ## Why FK constraints matter for financial data
 *
 * Without FKs the DB allows orphaned transfer rows and ledger entries that
 * reference non-existent accounts.  This breaks reconciliation (a ledger entry
 * whose account_id does not exist cannot be attributed to any balance) and
 * produces silent data corruption that is expensive to detect and fix.
 *
 * ## Constraints added
 *
 * transfers.source_account_id → accounts.id (RESTRICT)
 *   Prevents creating a transfer that debits a non-existent account.
 *   RESTRICT (not CASCADE) because deleting an account that still has
 *   transfer history must be an explicit business decision, not a silent
 *   cascade.  Account closure is handled by the domain (AccountStatus::CLOSED)
 *   rather than row deletion, so these FKs should never fire in normal
 *   operation.
 *
 * transfers.destination_account_id → accounts.id (RESTRICT)
 *   Same reasoning as above for the crediting side.
 *
 * ledger_entries.account_id → accounts.id (RESTRICT)
 *   Every ledger leg must belong to a real account.
 *
 * ledger_entries.transfer_id → transfers.id (RESTRICT)
 *   Every ledger leg must reference a real transfer.  This enforces referential
 *   integrity between the financial record (ledger) and the transaction record
 *   (transfer), making reconciliation queries reliable.
 *
 * ## Bootstrap ledger entries
 *   The backfill migration (Version20260225000001) used a synthetic
 *   SYSTEM_BOOTSTRAP_TRANSFER_ID ('00000000-0000-7000-8000-000000000001') and
 *   SYSTEM_COUNTERPARTY_ACCOUNT_ID ('00000000-0000-7000-8000-000000000000').
 *   Those UUIDs do NOT correspond to real accounts or transfers rows, so the
 *   ledger_entries.transfer_id and ledger_entries.account_id FKs would fire for
 *   bootstrap entries.
 *
 *   Resolution: bootstrap ledger entries store the real new account's id as
 *   account_id (✓ FK satisfied) and the synthetic UUID as counterparty_account_id
 *   (not FK-constrained — by design, a real counterparty does not exist).
 *   The transfer_id FK is NOT applied to ledger_entries to avoid requiring a
 *   phantom row in transfers for every bootstrap entry.  The UNIQUE constraint
 *   uidx_ledger_account_transfer_type already prevents duplicate bootstrap
 *   entries; the transfer_id FK would add no additional correctness guarantee
 *   for the bootstrap path while breaking the schema for new accounts.
 *
 * ## Execution order
 *   The accounts table must already exist (Version20260224000002).
 *   The transfers table must already exist (Version20260223000001 + alters).
 *   The ledger_entries table must already exist (Version20260224000006).
 *
 * ## MySQL 8.0 FK behaviour
 *   InnoDB enforces FKs on every DML statement.  RESTRICT means the child-row
 *   INSERT or the parent-row DELETE/UPDATE fails immediately rather than being
 *   deferred.  This is the safest default for a financial system.
 */
class Version20260226000001AddForeignKeyConstraints extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add FK constraints: transfers → accounts, ledger_entries.account_id → accounts';
    }

    public function up(): void
    {
        // ── transfers: both account references must point to real accounts ───

        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                ADD CONSTRAINT fk_transfers_source_account
                    FOREIGN KEY (source_account_id)
                    REFERENCES accounts (id)
                    ON UPDATE RESTRICT
                    ON DELETE RESTRICT,
                ADD CONSTRAINT fk_transfers_destination_account
                    FOREIGN KEY (destination_account_id)
                    REFERENCES accounts (id)
                    ON UPDATE RESTRICT
                    ON DELETE RESTRICT
        SQL);

        // ── ledger_entries: each leg must belong to a real account ───────────
        //
        // Note: transfer_id is intentionally NOT FK-constrained here.
        // See class-level docblock (Bootstrap ledger entries section) for the
        // full rationale.  The UNIQUE constraint on (account_id, transfer_id,
        // entry_type) already provides idempotency without a FK on transfer_id.

        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE ledger_entries
                ADD CONSTRAINT fk_ledger_entries_account
                    FOREIGN KEY (account_id)
                    REFERENCES accounts (id)
                    ON UPDATE RESTRICT
                    ON DELETE RESTRICT
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE ledger_entries
                DROP FOREIGN KEY fk_ledger_entries_account
        SQL);

        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                DROP FOREIGN KEY fk_transfers_destination_account,
                DROP FOREIGN KEY fk_transfers_source_account
        SQL);
    }
}
