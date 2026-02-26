<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Creates the idempotency_keys table used by IdempotencySubscriber.
 *
 * Each row represents one unique client-supplied X-Idempotency-Key.
 * The response (status + body) is cached for 24 hours so that identical
 * retries on POST /transfers return the same result without re-executing
 * the transfer handler.
 *
 * request_hash (SHA-256 of request body) is stored to detect key reuse with
 * a different payload — which is always a client bug and must be rejected 422.
 */
final class Version20260224000003CreateIdempotencyKeysTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates idempotency_keys table for POST /transfers deduplication';
    }

    public function up(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            CREATE TABLE idempotency_keys (
                idempotency_key  VARCHAR(255)  NOT NULL,
                request_hash     CHAR(64)      NOT NULL COMMENT 'SHA-256 of request body — detects key reuse with different payload',
                response_status  SMALLINT      NOT NULL,
                response_body    JSON          NOT NULL,
                created_at       DATETIME(6)   NOT NULL,
                expires_at       DATETIME(6)   NOT NULL COMMENT 'TTL: 24 hours after created_at',
                PRIMARY KEY (idempotency_key),
                INDEX idx_idempotency_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Idempotency cache for POST /transfers — prevents duplicate money movement on client retries'
            SQL
        );
    }

    public function down(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS idempotency_keys');
    }
}
