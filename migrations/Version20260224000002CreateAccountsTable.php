<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\AbstractMigration;

/**
 * Create the `accounts` table.
 *
 * ## Column rationale
 *
 *  id                   CHAR(36)         UUID v4 — account identity
 *  owner_name           VARCHAR(255)     Human-readable holder name (AML/KYC, statements)
 *  currency             CHAR(3)          ISO 4217 code; fixed-length for tight storage
 *  balance_minor_units  BIGINT UNSIGNED  Balance in minor units; UNSIGNED prevents
 *                                        negative storage at the DB layer (double guard)
 *  status               VARCHAR(20)      AccountStatus enum: active | frozen | closed
 *  created_at           DATETIME(6)      UTC, immutable
 *  updated_at           DATETIME(6)      UTC, updated on every balance/status mutation
 *  version              INT              Optimistic-lock counter, incremented on mutation
 *
 * ## Index strategy
 *  PRIMARY KEY (id)         — unique lookup by account ID
 *  idx_accounts_status      — batch-process / admin queries by lifecycle state
 *
 * ## MySQL 8.0 strict-mode compliance
 *  - All columns NOT NULL with explicit defaults
 *  - No zero-date defaults
 *  - CHAR(36) for UUIDs (readable in logs/queries without UDF helpers)
 *  - BIGINT UNSIGNED blocks negative balance at storage level (domain also enforces)
 */
class Version20260224000002CreateAccountsTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts table with balance, status, and optimistic-lock version';
    }

    public function up(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS accounts (
                id                  CHAR(36)         NOT NULL,
                owner_name          VARCHAR(255)     NOT NULL,
                currency            CHAR(3)          NOT NULL,
                balance_minor_units BIGINT UNSIGNED  NOT NULL DEFAULT 0,
                status              VARCHAR(20)      NOT NULL DEFAULT 'active',
                created_at          DATETIME(6)      NOT NULL,
                updated_at          DATETIME(6)      NOT NULL,
                version             INT              NOT NULL DEFAULT 0,

                PRIMARY KEY (id),

                INDEX idx_accounts_status (status)

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Account balance and lifecycle records'
        SQL);
    }

    public function down(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS accounts');
    }
}
