<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency;

/**
 * Port for persisting and retrieving idempotency records.
 *
 * Lives in the Domain layer so the Application and UI layers depend only
 * on this interface — never on Doctrine DBAL or any infrastructure type.
 */
interface IdempotencyRepositoryInterface
{
    /**
     * Return the stored record for the given key, or null if not found
     * (including when the record has expired and been pruned).
     */
    public function findByKey(string $idempotencyKey): ?IdempotencyRecord;

    /**
     * Persist a new idempotency record.
     *
     * Called once per successful or failed transfer attempt so that
     * subsequent retries with the same key return the cached response.
     */
    public function save(IdempotencyRecord $record): void;

    /**
     * Delete all records whose expires_at is in the past.
     *
     * Called by the app:idempotency:prune CLI command.
     *
     * @return int Number of rows deleted
     */
    public function deleteExpired(): int;

    /**
     * Acquire a DB-level advisory lock scoped to the given idempotency key.
     *
     * Blocks until the lock is acquired or $timeoutSeconds elapses.
     * Returns true when the lock was acquired, false on timeout.
     *
     * Used by IdempotencySubscriber to serialise concurrent first-requests
     * for the same key: the second caller blocks here until the first caller
     * finishes its handler and calls releaseLock().  At that point the second
     * caller re-runs findByKey() and hits the cached response, so the handler
     * is never executed twice.
     *
     * Implementation note: MySQL GET_LOCK() is connection-scoped and
     * re-entrant on the same connection, so there is no self-deadlock risk.
     *
     * @param int $timeoutSeconds Maximum wait in seconds; 0 = non-blocking.
     */
    public function acquireLock(string $idempotencyKey, int $timeoutSeconds = 5): bool;

    /**
     * Release the DB-level advisory lock previously acquired for the given key.
     *
     * No-op if the lock is not held by this connection.
     */
    public function releaseLock(string $idempotencyKey): void;
}
