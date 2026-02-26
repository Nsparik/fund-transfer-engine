<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Outgoing port for transactional boundaries in the Application layer.
 *
 * Lives in Shared so every bounded context can inject it without depending
 * on another module's Application layer.
 *
 * Both the Transfer and Account modules share the same MySQL connection and
 * transaction boundary — a single transactional() call covers writes to both
 * the accounts table and the transfers table atomically.
 *
 * ## Usage pattern
 *
 *   $this->transactionManager->transactional(function () use ($transfer): void {
 *       $this->accounts->save($source);
 *       $this->accounts->save($destination);
 *       $this->transfers->save($transfer);
 *       $this->outbox->save(...);
 *   });
 *   // Dispatch domain events ONLY after the closure above returns (commit done).
 */
interface TransactionManagerInterface
{
    /**
     * Execute $operation inside a database transaction.
     * Commits automatically on success; rolls back and rethrows on any Throwable.
     *
     * @template T
     * @param  callable(): T $operation
     * @return T
     * @throws \Throwable rethrows whatever the operation throws, after rollback
     */
    public function transactional(callable $operation): mixed;
}
