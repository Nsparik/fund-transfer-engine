<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;

/**
 * MigrationRunner
 *
 * Discovers migration classes in /migrations, applies them in version
 * order, and tracks applied versions in the `schema_migrations` table.
 *
 * ## Discovery contract
 *   - Files:   migrations/Version*.php
 *   - Classes: named exactly after their file (no namespace — plain PHP files).
 *   - Sort:    ascending by the numeric version prefix in the class name.
 *
 * ## schema_migrations table schema
 *   version     VARCHAR(100) PRIMARY KEY
 *   applied_at  DATETIME     NOT NULL
 */
final class MigrationRunner
{
    private const MIGRATIONS_TABLE   = 'schema_migrations';
    private const MIGRATIONS_DIR     = __DIR__ . '/../../../../migrations';
    private const ADVISORY_LOCK_NAME = 'fund_transfer_migrations';
    private const ADVISORY_LOCK_WAIT = 10; // seconds to wait before failing
    private const DATETIME_FORMAT    = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Run all pending migrations in ascending version order.
     *
     * @return list<string> version strings that were applied
     */
    public function migrate(): array
    {
        // Acquire the advisory lock BEFORE creating/checking the migrations table.
        // Two concurrent deployments must not race on CREATE TABLE schema_migrations.
        $this->acquireAdvisoryLock();

        try {
            $this->ensureMigrationsTable();
            $applied  = $this->getAppliedVersions();
            $pending  = $this->getPendingMigrations($applied);
            $executed = [];

            foreach ($pending as $version => $class) {
                /** @var AbstractMigration $migration */
                $migration = new $class($this->connection);

                // Wrap each migration and its record-keeping in a transaction.
                // MySQL DDL (CREATE TABLE, ALTER TABLE) causes an implicit commit,
                // so DDL migrations cannot be rolled back — however the transaction
                // still guarantees markApplied() is atomic with any DML migrations.
                $this->connection->beginTransaction();
                try {
                    $migration->up();
                    $this->markApplied((string) $version);
                    $this->commitIfActive();
                } catch (\Throwable $e) {
                    $this->rollbackIfActive();
                    throw $e;
                }

                $executed[] = (string) $version;
            }

            return $executed;
        } finally {
            $this->releaseAdvisoryLock();
        }
    }

    /**
     * Roll back the last applied migration.
     *
     * @return string|null the version that was rolled back, or null if none
     */
    public function rollback(): ?string
    {
        $this->acquireAdvisoryLock();

        try {
            $this->ensureMigrationsTable();
            $applied = $this->getAppliedVersions();
            if (empty($applied)) {
                return null;
            }

            $version = end($applied);
            $class   = $this->resolveClass($version);

            /** @var AbstractMigration $migration */
            $migration = new $class($this->connection);

            $this->connection->beginTransaction();
            try {
                $migration->down();
                $this->markRolledBack($version);
                $this->commitIfActive();
            } catch (\Throwable $e) {
                $this->rollbackIfActive();
                throw $e;
            }

            return $version;
        } finally {
            $this->releaseAdvisoryLock();
        }
    }

    /**
     * Return a list of all discovered migrations with their applied status.
     *
     * @return list<array{version: string, class: string, status: string, applied_at: string|null, description: string}>
     */
    public function getStatus(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->getAppliedVersions();
        $all     = $this->discoverAll();

        // Single query for all applied_at timestamps — avoids N+1 per migration.
        $appliedAtMap = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT version, applied_at FROM ' . self::MIGRATIONS_TABLE
        ) as $row) {
            $appliedAtMap[(string) $row['version']] = (string) $row['applied_at'];
        }

        $status  = [];

        foreach ($all as $version => $class) {
            $version = (string) $version;

            /** @var AbstractMigration $m */
            $m = new $class($this->connection);

            $status[] = [
                'version'     => $version,
                'class'       => $class,
                'status'      => in_array($version, $applied, true) ? 'applied' : 'pending',
                'applied_at'  => $appliedAtMap[$version] ?? null,
                'description' => $m->getDescription(),
            ];
        }

        return $status;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function ensureMigrationsTable(): void
    {
        $sm = $this->connection->createSchemaManager();

        if ($sm->tablesExist([self::MIGRATIONS_TABLE])) {
            return;
        }

        $table = new Table(self::MIGRATIONS_TABLE);
        $table->addColumn('version', 'string', ['length' => 100]);
        $table->addColumn('applied_at', 'datetime');
        $table->setPrimaryKey(['version']);

        $sm->createTable($table);
    }

    /**
     * @return list<string> sorted ascending
     */
    private function getAppliedVersions(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT version FROM ' . self::MIGRATIONS_TABLE . ' ORDER BY version ASC'
        );

        return $rows;
    }

    /**
     * @param  list<string>         $applied
     * @return array<string,string> version => FQCN, sorted ascending
     */
    private function getPendingMigrations(array $applied): array
    {
        $all     = $this->discoverAll();
        $pending = [];

        foreach ($all as $version => $class) {
            if (!in_array((string) $version, $applied, true)) {
                $pending[(string) $version] = $class;
            }
        }

        return $pending;
    }

    /**
     * @return array<string,string> version => FQCN, sorted ascending by version
     */
    private function discoverAll(): array
    {
        $dir = realpath(self::MIGRATIONS_DIR);

        if ($dir === false) {
            // migrations/ directory does not exist (e.g. fresh container, wrong mount).
            return [];
        }

        $files = glob($dir . '/Version*.php') ?: [];
        $map   = [];

        foreach ($files as $file) {
            require_once $file;
            $class   = basename($file, '.php');
            $version = $this->extractVersion($class);
            $map[$version] = $class;
        }

        ksort($map, SORT_STRING);

        return $map;
    }

    private function extractVersion(string $class): string
    {
        // VersionYYYYMMDDHHmmss* → extract the numeric prefix
        if (preg_match('/^Version(\d+)/', $class, $m)) {
            return $m[1];
        }

        return $class;
    }

    private function resolveClass(string $version): string
    {
        $all = $this->discoverAll();

        if (!isset($all[$version])) {
            throw new \RuntimeException(sprintf('Migration version "%s" not found.', $version));
        }

        return $all[$version];
    }

    private function markApplied(string $version): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . self::MIGRATIONS_TABLE . ' (version, applied_at) VALUES (?, ?)',
            [$version, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::DATETIME_FORMAT)]
        );
    }

    private function markRolledBack(string $version): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . self::MIGRATIONS_TABLE . ' WHERE version = ?',
            [$version]
        );
    }

    /**
     * Acquire a MySQL advisory lock to serialise concurrent deploy processes.
     *
     * Blue/green and rolling deployments may trigger migrations simultaneously;
     * the advisory lock prevents double-execution of the same version.
     *
     * @throws \RuntimeException if the lock cannot be acquired within the timeout
     */
    private function acquireAdvisoryLock(): void
    {
        $result = $this->connection->fetchOne(sprintf(
            "SELECT GET_LOCK('%s', %d)",
            self::ADVISORY_LOCK_NAME,
            self::ADVISORY_LOCK_WAIT,
        ));

        if ($result !== '1' && $result !== 1) {
            throw new \RuntimeException(sprintf(
                'Could not acquire migration advisory lock "%s" within %d second(s). '
                . 'Another deployment may currently be running migrations.',
                self::ADVISORY_LOCK_NAME,
                self::ADVISORY_LOCK_WAIT,
            ));
        }
    }

    private function releaseAdvisoryLock(): void
    {
        $this->connection->executeStatement(sprintf(
            "SELECT RELEASE_LOCK('%s')",
            self::ADVISORY_LOCK_NAME,
        ));
    }

    /**
     * Commit only if a transaction is still active on the server.
     *
     * MySQL DDL statements (ALTER TABLE, CREATE TABLE) cause an implicit COMMIT
     * that resets the server-side transaction state while DBAL's internal
     * nesting-level counter still reads 1.  Calling commit() in that state
     * produces "There is no active transaction" from the MySQL driver.
     *
     * Catching ConnectionException here keeps DDL migrations from crashing
     * the runner while still propagating real connection failures that occur
     * in pure-DML migrations.
     */
    private function commitIfActive(): void
    {
        try {
            $this->connection->commit();
        } catch (\Doctrine\DBAL\ConnectionException) {
            // DBAL guard: nesting level was already 0 — DDL auto-committed.
        } catch (\Throwable $e) {
            // MySQL driver (PDO) threw because DDL auto-committed the transaction
            // before we could. Re-throw anything that is not that specific signal.
            if (!str_contains($e->getMessage(), 'no active transaction')
                && !str_contains($e->getMessage(), 'There is no active transaction')
            ) {
                throw $e;
            }
        }
    }

    /**
     * Roll back only if a transaction is still active on the server.
     *
     * Same implicit-commit scenario as commitIfActive(): after a DDL statement
     * there is no open transaction to roll back.
     */
    private function rollbackIfActive(): void
    {
        try {
            $this->connection->rollBack();
        } catch (\Doctrine\DBAL\ConnectionException) {
            // DBAL guard: nesting level was already 0 — DDL auto-committed.
        } catch (\Throwable $e) {
            // MySQL driver (PDO) threw because DDL auto-committed the transaction.
            // Re-throw anything that is not that specific signal.
            if (!str_contains($e->getMessage(), 'no active transaction')
                && !str_contains($e->getMessage(), 'There is no active transaction')
            ) {
                throw $e;
            }
        }
    }
}
