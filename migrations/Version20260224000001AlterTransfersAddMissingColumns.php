<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Alter the `transfers` table to add fields required for production-grade
 * fintech operations.
 *
 * ## New columns
 *
 *  reference       VARCHAR(25) NOT NULL UNIQUE
 *      Human-readable ID (TXN-YYYYMMDD-XXXXXXXXXXXX) shown to customers in
 *      statements and support tickets.  Derived from the transfer UUID.
 *
 *  description     VARCHAR(500) NULL
 *      Optional payment narrative ("Rent Feb 2026").  Required by AML/KYC
 *      narrative rules and bank-statement generation.
 *
 *  failure_code    VARCHAR(100) NULL
 *      Machine-readable failure reason (e.g. INSUFFICIENT_FUNDS, ACCOUNT_SUSPENDED).
 *      NULL when status ≠ FAILED.
 *
 *  failure_reason  VARCHAR(500) NULL
 *      Human-readable failure explanation for support/audit/dispute resolution.
 *      NULL when status ≠ FAILED.
 *
 *  completed_at    DATETIME(6) NULL
 *      UTC timestamp recorded when the transfer reaches COMPLETED.
 *      Distinct from updated_at which changes on every transition.
 *
 *  failed_at       DATETIME(6) NULL
 *      UTC timestamp recorded when the transfer reaches FAILED.
 *
 *  version         INT NOT NULL DEFAULT 0
 *      Monotonically-incrementing optimistic-lock counter.  Bumped on every
 *      state transition.  Used with SELECT … FOR UPDATE row locking to guard
 *      against concurrent modifications.
 */
class Version20260224000001AlterTransfersAddMissingColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reference, description, failure tracking, completion timestamps and version to transfers table';
    }

    public function up(): void
    {
        // Step 1: Add new columns (nullable initially to allow ALTER on existing rows)
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                ADD COLUMN reference      VARCHAR(25)  NULL           AFTER id,
                ADD COLUMN description    VARCHAR(500) NULL           AFTER currency,
                ADD COLUMN failure_code   VARCHAR(100) NULL           AFTER status,
                ADD COLUMN failure_reason VARCHAR(500) NULL           AFTER failure_code,
                ADD COLUMN completed_at   DATETIME(6)  NULL           AFTER failure_reason,
                ADD COLUMN failed_at      DATETIME(6)  NULL           AFTER completed_at,
                ADD COLUMN version        INT          NOT NULL DEFAULT 0 AFTER updated_at
        SQL);

        // Step 2: Back-fill reference for any existing rows.
        // Derived deterministically: TXN-{YYYYMMDD from created_at}-{last 12 hex chars of id}
        // This mirrors TransferReference::generate() exactly so references are
        // consistent whether the row was created before or after this migration.
        $this->connection->executeStatement(<<<'SQL'
            UPDATE transfers
               SET reference = CONCAT(
                       'TXN-',
                       DATE_FORMAT(created_at, '%Y%m%d'),
                       '-',
                       UPPER(RIGHT(REPLACE(id, '-', ''), 12))
                   )
             WHERE reference IS NULL
        SQL);

        // Step 3: Make reference NOT NULL and enforce uniqueness
        $this->connection->executeStatement(
            'ALTER TABLE transfers MODIFY COLUMN reference VARCHAR(25) NOT NULL'
        );

        $this->connection->executeStatement(
            'ALTER TABLE transfers ADD UNIQUE INDEX uidx_transfers_reference (reference)'
        );
    }

    public function down(): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE transfers DROP INDEX uidx_transfers_reference'
        );

        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                DROP COLUMN reference,
                DROP COLUMN description,
                DROP COLUMN failure_code,
                DROP COLUMN failure_reason,
                DROP COLUMN completed_at,
                DROP COLUMN failed_at,
                DROP COLUMN version
        SQL);
    }
}
