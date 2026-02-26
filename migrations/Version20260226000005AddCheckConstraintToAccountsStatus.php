<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Add a DB-level CHECK constraint on accounts.status.
 *
 * ## Problem
 *   The PHP-side AccountStatus enum + canTransitionTo() enforce valid status
 *   transitions at the application layer.  However, accounts.status is a plain
 *   VARCHAR(20) column with no DB constraint.  A direct SQL INSERT or UPDATE
 *   (migration bug, data repair script, BI tooling) could write an arbitrary
 *   string without a DB error, corrupting account state silently.
 *
 * ## Fix
 *   A single CHECK constraint that mirrors the AccountStatus enum cases:
 *     active | frozen | closed
 *
 *   MySQL 8.0.16+ enforces CHECK constraints on INSERT and UPDATE.
 *   Expressions are stored and re-evaluated on every write.
 *
 * ## Non-disruptive
 *   The constraint is added via ALTER TABLE which acquires a metadata lock
 *   briefly but does not rewrite table data.  MySQL validates all existing rows
 *   inline; since existing data was written by the application, it already
 *   satisfies the constraint.  The migration is safe to apply to a live table.
 */
final class Version20260226000005AddCheckConstraintToAccountsStatus extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CHECK constraint on accounts.status to enforce valid enum values at the DB layer.';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE accounts
                ADD CONSTRAINT chk_accounts_status
                    CHECK (status IN ('active', 'frozen', 'closed'))
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE accounts DROP CONSTRAINT chk_accounts_status',
        );
    }
}
