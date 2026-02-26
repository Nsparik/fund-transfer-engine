<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Add idempotency_key column to the transfers table.
 *
 * ## Why this migration exists
 *
 * The HTTP-layer idempotency cache (IdempotencySubscriber + idempotency_keys
 * table) is saved in a SEPARATE round-trip AFTER the transfer transaction
 * commits.  If the PHP process is killed between the DB commit and the
 * idempotency record write (OOM, SIGKILL, max_execution_time), the client
 * receives no response.  The next retry passes the idempotency check (record
 * was never saved), enters the handler, and executes a second money movement
 * — a double-debit.
 *
 * This column closes that window by writing the idempotency key ATOMICALLY
 * inside the same DB transaction as the debit/credit/ledger writes.  The
 * InitiateTransferHandler checks transfers.idempotency_key BEFORE executing
 * the double-entry.  If a COMPLETED transfer is found for this key, the
 * existing DTO is returned without any money movement.
 *
 * ## Column definition
 *   VARCHAR(255) NULL  — matches idempotency_keys.idempotency_key length.
 *   NULL for system-initiated transfers (bootstrap, backfill) and legacy rows
 *   already committed before this migration was deployed.
 *
 * ## Uniqueness
 *   UNIQUE KEY ensures the DB itself rejects a duplicate committed transfer
 *   for the same key even if the application check is somehow bypassed.
 *   The UNIQUE index allows NULL (MySQL treats each NULL as distinct) so
 *   multiple transfers without a key do not conflict.
 *
 * ## Backward compatibility
 *   All existing transfers have idempotency_key = NULL.  The constraint is
 *   added to new commits only.  No backfill is necessary.
 *
 * ## Rollback safety
 *   down() drops the index then the column; both are non-destructive to
 *   financial data.
 */
final class Version20260226000003AddIdempotencyKeyToTransfers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idempotency_key column to transfers for atomic DB-level deduplication on process crash/retry';
    }

    public function up(): void
    {
        // Placed after `description` so related narrative fields are grouped.
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                ADD COLUMN idempotency_key VARCHAR(255) NULL DEFAULT NULL
                    COMMENT 'Client X-Idempotency-Key written atomically with the transfer commit; NULL for system-initiated or pre-migration transfers'
                    AFTER description
        SQL);

        // Last-resort guard: even if the application check fails,
        // the DB will reject a second INSERT/UPDATE with the same key.
        // NULL values are each treated as distinct by MySQL, so transfers
        // without a key do not collide.
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE transfers
                ADD UNIQUE KEY uidx_transfers_idempotency_key (idempotency_key)
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE transfers DROP KEY uidx_transfers_idempotency_key'
        );

        $this->connection->executeStatement(
            'ALTER TABLE transfers DROP COLUMN idempotency_key'
        );
    }
}
