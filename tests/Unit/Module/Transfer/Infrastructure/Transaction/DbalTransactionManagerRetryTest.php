<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Transfer\Infrastructure\Transaction;

use App\Module\Transfer\Infrastructure\Transaction\DbalTransactionManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TC-4 — Deadlock Retry Validation
 *
 * Verifies that DbalTransactionManager:
 *   1. Retries on DeadlockException and succeeds on the second attempt.
 *   2. Re-throws DeadlockException after MAX_DEADLOCK_RETRIES (3) exhausted.
 *   3. Does NOT retry on non-deadlock exceptions.
 *   4. Returns the closure's return value correctly on a successful retry.
 *
 * Uses a mock Connection whose transactional() is programmed to throw on
 * the first N calls and succeed on attempt N+1 (or always throw for the
 * exhaustion test).
 *
 * ## Why this test matters
 *   DbalTransactionManager::transactional() is the single chokepoint for all
 *   DB transactions in the system.  A deadlock that propagates to the caller
 *   as an unhandled HTTP 500 causes a silent financial non-completion:
 *   the client received an error, may or may not retry, and the transfer was
 *   never persisted.  The retry logic converts a transient 1213 MySQL error
 *   into a transparent retry that the caller never observes.
 */
final class DbalTransactionManagerRetryTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $connection;

    /** @var \Doctrine\DBAL\Driver\Exception&MockObject */
    private \Doctrine\DBAL\Driver\Exception $driverException;

    protected function setUp(): void
    {
        $this->connection      = $this->createMock(Connection::class);
        $this->driverException = $this->createMock(\Doctrine\DBAL\Driver\Exception::class);
    }

    // ── Test 1: Retry succeeds on second attempt ──────────────────────────────

    public function testDeadlockIsRetriedAndSucceedsOnSecondAttempt(): void
    {
        $deadlock = new DeadlockException($this->driverException, null);
        $callCount = 0;

        $this->connection
            ->expects(self::exactly(2))
            ->method('transactional')
            ->willReturnCallback(function (callable $op) use ($deadlock, &$callCount): mixed {
                $callCount++;
                if ($callCount === 1) {
                    throw $deadlock;
                }
                // Second call: execute the closure and return its value.
                return $op();
            });

        $manager = new DbalTransactionManager($this->connection);
        $result  = $manager->transactional(fn () => 'expected_result');

        self::assertSame('expected_result', $result, 'Return value from closure must be propagated');
        self::assertSame(2, $callCount, 'transactional() must be called exactly twice (1 fail + 1 success)');
    }

    // ── Test 2: Re-throws after MAX_DEADLOCK_RETRIES (3) ─────────────────────

    public function testDeadlockIsReThrownAfterMaxRetriesAreExhausted(): void
    {
        $deadlock = new DeadlockException($this->driverException, null);

        // Connection always throws — all 3 retries will fail.
        $this->connection
            ->expects(self::exactly(3))
            ->method('transactional')
            ->willThrowException($deadlock);

        $manager = new DbalTransactionManager($this->connection);

        $this->expectException(DeadlockException::class);

        $manager->transactional(fn () => 'never_returned');
    }

    // ── Test 3: Non-deadlock exception is never retried ──────────────────────

    public function testNonDeadlockExceptionIsNotRetriedAndPropagatesImmediately(): void
    {
        $otherException = new \RuntimeException('Unexpected DB error');

        // Must be called exactly once — no retry for non-deadlock exceptions.
        $this->connection
            ->expects(self::once())
            ->method('transactional')
            ->willThrowException($otherException);

        $manager = new DbalTransactionManager($this->connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected DB error');

        $manager->transactional(fn () => 'never_returned');
    }

    // ── Test 4: Succeeds on attempt 3 of 3 ───────────────────────────────────

    public function testDeadlockOnFirstTwoAttemptsSucceedsOnThird(): void
    {
        $deadlock  = new DeadlockException($this->driverException, null);
        $callCount = 0;

        $this->connection
            ->expects(self::exactly(3))
            ->method('transactional')
            ->willReturnCallback(function (callable $op) use ($deadlock, &$callCount): mixed {
                $callCount++;
                if ($callCount < 3) {
                    throw $deadlock;
                }
                return $op();
            });

        $manager = new DbalTransactionManager($this->connection);
        $result  = $manager->transactional(fn () => 42);

        self::assertSame(42, $result, 'Return value must be correct on the third attempt');
        self::assertSame(3, $callCount, 'transactional() must be called exactly 3 times');
    }

    // ── Test 5: Closure return value is correctly passed through ─────────────

    public function testClosureReturnValueIsPreservedOnFirstAttempt(): void
    {
        $expected = ['key' => 'value', 'nested' => [1, 2, 3]];

        $this->connection
            ->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(fn (callable $op) => $op());

        $manager = new DbalTransactionManager($this->connection);
        $result  = $manager->transactional(fn () => $expected);

        self::assertSame($expected, $result, 'Complex closure return values must be passed through unchanged');
    }

    // ── Test 6: Null return from closure is preserved ─────────────────────────

    public function testClosureReturningNullIsPropagatedCorrectly(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(fn (callable $op) => $op());

        $manager = new DbalTransactionManager($this->connection);
        $result  = $manager->transactional(fn () => null);

        self::assertNull($result, 'null return from closure must be preserved');
    }
}
