<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Ledger Integrity Hardening — DB-level CHECK constraint on amount_minor_units.
 *
 * ## Why
 *
 * The BIGINT UNSIGNED column type already prevents negative values (≥ 0), but
 * it cannot prevent a zero-amount entry.  The domain guard
 * LedgerEntry::assertPositiveAmount() enforces amount > 0 at the PHP level, yet
 * defence-in-depth dictates that the database should reject malformed rows
 * independently — protecting against direct SQL inserts, future framework
 * bypasses, or bugs in untested code paths.
 *
 * ## MySQL version requirement
 *
 * CHECK constraints are ENFORCED from MySQL 8.0.16 onwards.  On earlier versions
 * the syntax is accepted but silently ignored.  The project minimum is MySQL 8.0,
 * and all environments referenced in docker-compose.yml run 8.0+, so enforcement
 * is guaranteed.
 *
 * ## What this migration adds
 *
 *   ledger_entries.amount_minor_units > 0
 *       → every ledger row must represent a real monetary movement.
 *
 * Note: balance_after_minor_units deliberately has NO CHECK(> 0) because
 * a complete account drain produces balance_after = 0, which is valid.
 * The BIGINT UNSIGNED column type already prevents negative balances.
 */
class Version20260226000002AddLedgerAmountCheckConstraint extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DB-level CHECK(amount_minor_units > 0) to ledger_entries for defence-in-depth';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE ledger_entries
                ADD CONSTRAINT chk_ledger_entries_amount_positive
                    CHECK (amount_minor_units > 0)
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE ledger_entries
                DROP CHECK chk_ledger_entries_amount_positive
        SQL);
    }
}
