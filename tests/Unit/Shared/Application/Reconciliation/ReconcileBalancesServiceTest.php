<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Reconciliation;

use App\Shared\Application\DTO\ReconciliationResult;
use App\Shared\Application\DTO\ReconciliationRow;
use App\Shared\Application\Port\ReconciliationRepositoryInterface;
use App\Shared\Application\Reconciliation\ReconcileBalancesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReconcileBalancesService.
 *
 * Verifies that the service correctly maps ReconciliationRow objects (from the
 * mocked repository) to ReconciliationResult objects with the correct status,
 * diffMinorUnits, and isHealthy() value.
 *
 * All persistence is mocked — no DB required.
 */
final class ReconcileBalancesServiceTest extends TestCase
{
    private const ACC_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ACC_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ACC_C = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const ACC_D = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private ReconciliationRepositoryInterface&MockObject $repository;
    private ReconcileBalancesService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ReconciliationRepositoryInterface::class);
        $this->service    = new ReconcileBalancesService($this->repository);
    }

    // ── Empty account set ─────────────────────────────────────────────────────

    public function testReturnsEmptyArrayWhenNoAccounts(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([]);

        self::assertSame([], $this->service->reconcile());
    }

    // ── STATUS_MATCH — ledger entry present, balances equal ───────────────────

    public function testMatchWhenLedgerAndAccountBalancesAreEqual(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_A, 'USD', 5_000, 5_000),
        ]);

        $results = $this->service->reconcile();

        self::assertCount(1, $results);
        self::assertSame(ReconciliationResult::STATUS_MATCH, $results[0]->status);
        self::assertSame(0, $results[0]->diffMinorUnits);
        self::assertTrue($results[0]->isHealthy());
    }

    public function testMatchWhenBothBalancesAreZero(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_A, 'EUR', 0, 0),
        ]);

        $results = $this->service->reconcile();

        self::assertSame(ReconciliationResult::STATUS_MATCH, $results[0]->status);
        self::assertSame(0, $results[0]->diffMinorUnits);
        self::assertTrue($results[0]->isHealthy());
    }

    // ── STATUS_MATCH — no ledger entry, zero account balance ─────────────────

    public function testMatchWhenZeroBalanceWithNoLedgerEntry(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_A, 'GBP', 0, null),
        ]);

        $results = $this->service->reconcile();

        self::assertSame(ReconciliationResult::STATUS_MATCH, $results[0]->status);
        self::assertSame(0, $results[0]->diffMinorUnits);
        self::assertTrue($results[0]->isHealthy());
    }

    // ── STATUS_MISMATCH — ledger entry exists but balances differ ─────────────

    public function testMismatchWhenAccountBalanceIsHigherThanLedger(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_B, 'USD', 6_000, 5_000),
        ]);

        $results = $this->service->reconcile();

        self::assertSame(ReconciliationResult::STATUS_MISMATCH, $results[0]->status);
        self::assertSame(1_000, $results[0]->diffMinorUnits);
        self::assertFalse($results[0]->isHealthy());
    }

    public function testMismatchDiffIsNegativeWhenAccountBalanceIsLower(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_B, 'GBP', 3_000, 5_000),
        ]);

        $results = $this->service->reconcile();

        self::assertSame(ReconciliationResult::STATUS_MISMATCH, $results[0]->status);
        self::assertSame(-2_000, $results[0]->diffMinorUnits);
        self::assertFalse($results[0]->isHealthy());
    }

    public function testMismatchWhenLedgerBalanceIsZeroButAccountIsNot(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_B, 'USD', 500, 0),
        ]);

        $results = $this->service->reconcile();

        self::assertSame(ReconciliationResult::STATUS_MISMATCH, $results[0]->status);
        self::assertSame(500, $results[0]->diffMinorUnits);
    }

    // ── STATUS_NO_LEDGER_ENTRY — no entry, non-zero balance ───────────────────

    public function testNoLedgerEntryWhenNonZeroBalanceAndNoLedgerEntry(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_C, 'USD', 10_000, null),
        ]);

        $results = $this->service->reconcile();

        self::assertSame(ReconciliationResult::STATUS_NO_LEDGER_ENTRY, $results[0]->status);
        self::assertSame(10_000, $results[0]->diffMinorUnits);
        self::assertFalse($results[0]->isHealthy());
    }

    // ── Multiple accounts — mixed statuses ────────────────────────────────────

    public function testMixedStatusesAcrossFourAccounts(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_A, 'USD', 5_000,  5_000),  // match
            new ReconciliationRow(self::ACC_B, 'USD', 6_000,  5_000),  // mismatch
            new ReconciliationRow(self::ACC_C, 'EUR', 10_000, null),   // no_ledger_entry
            new ReconciliationRow(self::ACC_D, 'GBP', 0,      null),   // match (zero, no entries)
        ]);

        $results = $this->service->reconcile();

        self::assertCount(4, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->accountId] = $r;
        }

        self::assertSame(ReconciliationResult::STATUS_MATCH,           $byId[self::ACC_A]->status);
        self::assertSame(ReconciliationResult::STATUS_MISMATCH,        $byId[self::ACC_B]->status);
        self::assertSame(ReconciliationResult::STATUS_NO_LEDGER_ENTRY, $byId[self::ACC_C]->status);
        self::assertSame(ReconciliationResult::STATUS_MATCH,           $byId[self::ACC_D]->status);
    }

    // ── Result field pass-through ─────────────────────────────────────────────

    public function testAllResultFieldsArePassedThroughFromRow(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_A, 'JPY', 123_456, 123_456),
        ]);

        $result = $this->service->reconcile()[0];

        self::assertSame(self::ACC_A, $result->accountId);
        self::assertSame('JPY',       $result->currency);
        self::assertSame(123_456,     $result->accountBalance);
        self::assertSame(123_456,     $result->ledgerBalance);
    }

    public function testOrderOfResultsMatchesOrderFromRepository(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_C, 'USD', 100, 100),
            new ReconciliationRow(self::ACC_A, 'USD', 200, 200),
            new ReconciliationRow(self::ACC_B, 'USD', 300, 300),
        ]);

        $results = $this->service->reconcile();

        self::assertSame(self::ACC_C, $results[0]->accountId);
        self::assertSame(self::ACC_A, $results[1]->accountId);
        self::assertSame(self::ACC_B, $results[2]->accountId);
    }

    // ── Immutability — ReconciliationResult must be fully readonly ────────────

    public function testReconciliationResultIsImmutable(): void
    {
        // All public properties on ReconciliationResult must be readonly.
        // This test guards against future accidental removal of 'readonly' from
        // computed fields ($status, $diffMinorUnits) or promoted constructor params.
        $ref = new \ReflectionClass(ReconciliationResult::class);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            self::assertTrue(
                $prop->isReadOnly(),
                sprintf(
                    'ReconciliationResult::$%s must be declared readonly — it is a fintech immutable value object.',
                    $prop->getName(),
                ),
            );
        }
    }

    // ── diffMinorUnits is exactly 0 for STATUS_MATCH, even when no ledger entry ─

    public function testMatchDiffIsZeroWhenNullLedgerAndZeroBalance(): void
    {
        // Bug guard: previously diffMinorUnits was set to $accountBalance (= 0 by coincidence)
        // for the null+zero path. It must be explicitly 0 for any STATUS_MATCH result.
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_A, 'USD', 0, null),
        ]);

        $result = $this->service->reconcile()[0];

        self::assertSame(ReconciliationResult::STATUS_MATCH, $result->status);
        self::assertSame(0, $result->diffMinorUnits, 'diff must be 0 for STATUS_MATCH regardless of null ledger entry');
        self::assertTrue($result->isHealthy());
    }

    public function testMatchDiffIsZeroWhenLedgerAndAccountBothNonZeroAndEqual(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_A, 'EUR', 99_999, 99_999),
        ]);

        $result = $this->service->reconcile()[0];

        self::assertSame(ReconciliationResult::STATUS_MATCH, $result->status);
        self::assertSame(0, $result->diffMinorUnits);
    }

    // ── no_ledger_entry diffMinorUnits equals the account balance ─────────────

    public function testNoLedgerEntryDiffEqualsAccountBalance(): void
    {
        $this->repository->method('findAllForReconciliation')->willReturn([
            new ReconciliationRow(self::ACC_C, 'GBP', 7_500, null),
        ]);

        $result = $this->service->reconcile()[0];

        self::assertSame(ReconciliationResult::STATUS_NO_LEDGER_ENTRY, $result->status);
        self::assertSame(7_500, $result->diffMinorUnits, 'diff for no_ledger_entry must equal accountBalance (unrecorded amount)');
    }
}
