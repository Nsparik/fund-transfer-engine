<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Add DB-level CHECK constraints to the transfers table for defence-in-depth.
 *
 * ## Why
 *
 * The domain model (TransferStatus enum, Transfer::initiate() guard,
 * Money value object) enforces these invariants in PHP.  DB-level constraints
 * provide an independent second line of defence that is bypassed ONLY by
 * direct SQL writes (admin scripts, broken migrations, future framework
 * integration).  For a payment system, silent corruption from a direct INSERT
 * is more dangerous than a hard constraint failure.
 *
 * ## MySQL version requirement
 *   CHECK constraints are ENFORCED from MySQL 8.0.16+.  This project targets
 *   MySQL 8.0, so enforcement is guaranteed in all environments.
 *
 * ## Constraints added
 *
 *   chk_transfers_status
 *     Rejects any status value not in the TransferStatus enum.
 *     Keeps the DB and the PHP state machine in sync: adding a new enum case
 *     requires updating this constraint (intentional friction — schema changes
 *     for new states must be explicit migration decisions).
 *
 *   chk_transfers_amount_positive
 *     Mirrors Money::__construct() guard and Transfer::initiate()'s
 *     InvalidTransferAmountException.  Zero-amount transfers are a no-op
 *     financially but would silently pollute the audit trail.
 *
 *   chk_transfers_different_accounts
 *     Mirrors Transfer::initiate()'s SameAccountTransferException.
 *     A self-transfer at the DB layer would produce two ledger entries for
 *     the same account at the same moment — valid per the UNIQUE constraint
 *     on (account_id, transfer_id, entry_type) but financially meaningless
 *     and hard to detect in reconciliation.
 */
final class Version20260226000004AddCheckConstraintsToTransfers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CHECK constraints to transfers: valid status enum, positive amount, and distinct accounts';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                ADD CONSTRAINT chk_transfers_status
                    CHECK (status IN ('pending','processing','completed','failed','reversed')),
                ADD CONSTRAINT chk_transfers_amount_positive
                    CHECK (amount_minor_units > 0),
                ADD CONSTRAINT chk_transfers_different_accounts
                    CHECK (source_account_id <> destination_account_id)
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                DROP CHECK chk_transfers_different_accounts,
                DROP CHECK chk_transfers_amount_positive,
                DROP CHECK chk_transfers_status
        SQL);
    }
}
