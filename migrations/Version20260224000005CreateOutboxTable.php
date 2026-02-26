<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Create the outbox_events table.
 *
 * Stores domain events atomically inside the same DB transaction as the
 * business operation, guaranteeing at-least-once delivery even if the
 * PHP process dies between DB commit and in-process dispatch.
 *
 * Polling query: SELECT … WHERE published_at IS NULL ORDER BY created_at ASC
 * The composite index idx_outbox_unpublished covers both the WHERE filter and
 * the ORDER BY efficiently.
 *
 * SKIP LOCKED on the polling query prevents two concurrent worker processes
 * from picking the same batch of events.
 */
final class Version20260224000005CreateOutboxTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create outbox_events table for reliable domain event delivery';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS outbox_events (
                id              CHAR(36)        NOT NULL,
                aggregate_type  VARCHAR(100)    NOT NULL COMMENT 'e.g. Transfer, Account',
                aggregate_id    CHAR(36)        NOT NULL COMMENT 'UUID of the originating aggregate',
                event_type      VARCHAR(255)    NOT NULL COMMENT 'FQCN of the domain event class',
                payload         JSON            NOT NULL,
                occurred_at     DATETIME(6)     NOT NULL COMMENT 'UTC timestamp from the domain event',
                published_at    DATETIME(6)     NULL     DEFAULT NULL,
                attempt_count   INT UNSIGNED    NOT NULL DEFAULT 0,
                last_error      TEXT            NULL     DEFAULT NULL,
                created_at      DATETIME(6)     NOT NULL,

                PRIMARY KEY (id),
                INDEX idx_outbox_unpublished (published_at, created_at),
                INDEX idx_outbox_aggregate   (aggregate_type, aggregate_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Transactional outbox for reliable domain event delivery'
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS outbox_events');
    }
}
