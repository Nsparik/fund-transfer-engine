<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Add closed_at timestamp column to the accounts table.
 *
 * Records the exact moment an account was closed.  The domain tracks closure
 * through the status='closed' value, but a queryable timestamp enables:
 *   - Audit queries such as "accounts closed in the last 30 days".
 *   - GET /accounts/{id} responses that expose the closure time via AccountDTO.
 *   - Admin tooling that needs to reason about when a closure occurred.
 *
 * The column is set atomically when AccountStatus transitions to CLOSED inside
 * CloseAccountHandler.  It is NULL for all active and frozen accounts, and for
 * any closed accounts that pre-date this migration.
 *
 * ## Column definition
 *   DATETIME(6) NULL — microsecond precision, UTC, nullable.
 *   Placed after updated_at so related timestamp fields are grouped together.
 *
 * ## Non-disruptive
 *   Adding a nullable column with no default performs a metadata-only change in
 *   MySQL 8.0 InnoDB (ALGORITHM=INSTANT is selected automatically).  The table
 *   is not rebuilt and no row-level lock is required.
 */
final class Version20260226000006AddClosedAtToAccounts extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add closed_at (nullable DATETIME(6)) to accounts; records the UTC moment an account was closed';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE accounts
                ADD COLUMN closed_at DATETIME(6) NULL DEFAULT NULL
                    COMMENT 'UTC timestamp when the account was closed; NULL for active or frozen accounts'
                    AFTER updated_at
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE accounts DROP COLUMN closed_at',
        );
    }
}
