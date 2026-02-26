<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Add reversed_at timestamp to the transfers table.
 *
 * ## Why a dedicated column
 *   reversed_at is semantically distinct from updated_at (which changes on every
 *   state transition).  Having an explicit nullable timestamp for the REVERSED
 *   state allows:
 *     - Fast index-range queries: "all reversals in the last 7 days"
 *     - Audit/compliance exports: deterministic reversal time distinct from
 *       any subsequent status changes
 *     - Consistency with completed_at and failed_at (one dedicated column per
 *       terminal state) — consistent with completed_at and failed_at
 *       (one dedicated timestamp column per terminal state).
 *
 * ## Column definition
 *   DATETIME(6) NULL — microsecond precision UTC timestamp; NULL until reversed.
 *   Placed after failed_at to mirror the chronological progression of the
 *   state machine: completed_at → failed_at → reversed_at.
 */
final class Version20260224000004AlterTransfersAddReversedAt extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reversed_at timestamp column to transfers table';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                ADD COLUMN reversed_at DATETIME(6) NULL DEFAULT NULL COMMENT 'UTC timestamp set when transfer reaches REVERSED status'
                AFTER failed_at
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE transfers DROP COLUMN reversed_at'
        );
    }
}
