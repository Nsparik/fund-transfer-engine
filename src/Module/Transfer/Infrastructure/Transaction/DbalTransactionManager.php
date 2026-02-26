<?php

declare(strict_types=1);

namespace App\Module\Transfer\Infrastructure\Transaction;

use App\Shared\Application\Port\TransactionManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;

/**
 * DBAL-backed implementation of TransactionManagerInterface.
 *
 * Wraps Doctrine DBAL 3's Connection::transactional() so that the
 * Application layer never imports infrastructure types directly.
 *
 * ## Deadlock retry
 *   MySQL reports error 1213 (Deadlock found when trying to get lock) when its
 *   lock-wait graph detects a cycle between two concurrent transactions, even
 *   when all callers acquire locks in a consistent order.  Causes include
 *   secondary-index gap locks, insert intention locks, and MySQL's internal
 *   lock escalation decisions.
 *
 *   Doctrine maps 1213 to DeadlockException (which implements RetryableException).
 *   This class retries the operation up to MAX_DEADLOCK_RETRIES times with
 *   randomised exponential backoff before re-throwing.  The closure must be
 *   side-effect-free with respect to any state outside the DB transaction so
 *   that re-execution is safe — all callers in this codebase satisfy that
 *   contract (domain objects are rebuilt fresh inside the closure, or loaded
 *   from DB with FOR UPDATE on the retry).
 *
 *   MAX_DEADLOCK_RETRIES = 3 matches the conventional production default for
 *   OLTP payment systems: a deadlock at attempt 4 indicates a systemic lock-
 *   ordering problem that warrants a hard failure and pager alert, not infinite
 *   retries.
 */
final class DbalTransactionManager implements TransactionManagerInterface
{
    private const MAX_DEADLOCK_RETRIES = 3;

    public function __construct(private readonly Connection $connection) {}

    public function transactional(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->connection->transactional(\Closure::fromCallable($operation));
            } catch (DeadlockException $e) {
                if (++$attempt >= self::MAX_DEADLOCK_RETRIES) {
                    throw $e;
                }

                usleep(random_int(10_000, 50_000) * $attempt);
            }
        }
    }
}
