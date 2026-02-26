<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;
use Doctrine\DBAL\Connection;

/**
 * Create the `transfers` table.
 *
 * ## Column rationale
 *
 *  id                     CHAR(36)         UUIDv7 (time-ordered) — natural insert order matches B-tree
 *  source_account_id      CHAR(36)         UUID of the debiting account
 *  destination_account_id CHAR(36)         UUID of the crediting account
 *  amount_minor_units     BIGINT UNSIGNED  Amount in minor units (cents); UNSIGNED prevents negative storage
 *  currency               CHAR(3)          ISO 4217 code; fixed length for tight storage
 *  status                 VARCHAR(20)      TransferStatus enum value
 *  created_at             DATETIME(6)      Microsecond precision, always UTC
 *  updated_at             DATETIME(6)      Microsecond precision, always UTC
 *
 * ## Index strategy
 *  PRIMARY KEY (id)                         — unique lookup by transfer ID
 *  idx_source_account_created_at            — fetch outgoing transfers for an account, newest first
 *  idx_destination_account_created_at       — fetch incoming transfers for an account, newest first
 *  idx_status                               — filter/batch-process by lifecycle state
 *
 * ## MySQL 8.0 strict-mode compliance
 *  - All columns NOT NULL with explicit defaults where appropriate
 *  - No zero-date defaults (DEFAULT CURRENT_TIMESTAMP(6) instead of '0000-…')
 *  - CHAR(36) chosen over BINARY(16) for readability in queries/logs without UDF
 */
class Version20260223000001CreateTransfersTable extends AbstractMigration
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
    }

    public function getDescription(): string
    {
        return 'Create transfers table with indexes and strict-mode-compatible column types';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS transfers (
                id                      CHAR(36)         NOT NULL,
                source_account_id       CHAR(36)         NOT NULL,
                destination_account_id  CHAR(36)         NOT NULL,
                amount_minor_units      BIGINT UNSIGNED  NOT NULL,
                currency                CHAR(3)          NOT NULL,
                status                  VARCHAR(20)      NOT NULL,
                created_at              DATETIME(6)      NOT NULL,
                updated_at              DATETIME(6)      NOT NULL,

                PRIMARY KEY (id),

                INDEX idx_source_account_created_at      (source_account_id,      created_at DESC),
                INDEX idx_destination_account_created_at (destination_account_id, created_at DESC),
                INDEX idx_status                         (status)

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Fund transfer lifecycle records'
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS transfers');
    }
}
