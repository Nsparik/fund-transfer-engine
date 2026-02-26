<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;

/**
 * Base class for all application database migrations.
 *
 * A migration class must be placed in the `migrations/` directory at the
 * project root, following the naming convention:
 *
 *   Version{YYYYMMDDHHmmss}{Description}.php
 *   class Version{YYYYMMDDHHmmss}{Description} extends AbstractMigration
 *
 * The runner discovers, sorts, and applies them in version order.
 */
abstract class AbstractMigration
{
    public function __construct(
        protected readonly Connection $connection,
    ) {}

    /**
     * Apply the migration (schema changes, seed data, etc.).
     */
    abstract public function up(): void;

    /**
     * Reverse the migration.
     */
    abstract public function down(): void;

    /**
     * Human-readable one-liner shown in `app:migrations:status`.
     */
    abstract public function getDescription(): string;
}
