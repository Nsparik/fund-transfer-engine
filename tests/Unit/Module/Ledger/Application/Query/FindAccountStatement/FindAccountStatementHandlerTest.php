<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Ledger\Application\Query\FindAccountStatement;

use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\Model\AccountStatus;
use App\Module\Account\Domain\Repository\AccountRepositoryInterface;
use App\Module\Account\Domain\ValueObject\AccountId as AccountDomainId;
use App\Module\Account\Domain\ValueObject\Balance;
use App\Module\Ledger\Application\DTO\AccountStatementDTO;
use App\Module\Ledger\Application\Query\FindAccountStatement\FindAccountStatementHandler;
use App\Module\Ledger\Application\Query\FindAccountStatement\FindAccountStatementQuery;
use App\Module\Ledger\Domain\Exception\InvalidDateRangeException;
use App\Module\Ledger\Domain\Model\LedgerEntry;
use App\Module\Ledger\Domain\Model\LedgerPage;
use App\Module\Ledger\Domain\Repository\LedgerRepositoryInterface;
use App\Module\Ledger\Domain\ValueObject\AccountId as LedgerAccountId;
use App\Module\Ledger\Domain\ValueObject\EntryType;
use App\Module\Ledger\Domain\ValueObject\LedgerEntryId;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for FindAccountStatementHandler.
 *
 * Verifies:
 *   — Date range validation (from >= to, empty, > 366 days)
 *   — AccountNotFoundException propagation
 *   — Opening balance derived from findLastEntryBefore($from)
 *   — Closing balance derived from findLastEntryAtOrBefore($to)
 *   — No movements → opening = closing balance
 *   — Correct DTO fields populated
 *   — Pagination metadata correct
 */
final class FindAccountStatementHandlerTest extends TestCase
{
    private const ACCOUNT_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTHER_ID   = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const XFER_ID    = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    // ── Date range validation ─────────────────────────────────────────────────

    public function testFromAfterToThrowsInvalidDateRangeException(): void
    {
        $handler = $this->buildHandler(account: $this->makeAccount());

        $this->expectException(InvalidDateRangeException::class);
        $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-02-01T00:00:00Z',
            to:        '2026-01-01T00:00:00Z',
        ));
    }

    public function testEqualFromAndToThrowsInvalidDateRangeException(): void
    {
        $handler = $this->buildHandler(account: $this->makeAccount());

        $this->expectException(InvalidDateRangeException::class);
        $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-01T00:00:00Z',
        ));
    }

    public function testRangeOver366DaysThrowsInvalidDateRangeException(): void
    {
        $handler = $this->buildHandler(account: $this->makeAccount());

        $this->expectException(InvalidDateRangeException::class);
        $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2025-01-01T00:00:00Z',
            to:        '2026-04-01T00:00:00Z', // > 366 days
        ));
    }

    public function testEmptyFromThrowsInvalidDateRangeException(): void
    {
        $handler = $this->buildHandler(account: $this->makeAccount());

        $this->expectException(InvalidDateRangeException::class);
        $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '',
            to:        '2026-01-31T23:59:59Z',
        ));
    }

    public function testEmptyToThrowsInvalidDateRangeException(): void
    {
        $handler = $this->buildHandler(account: $this->makeAccount());

        $this->expectException(InvalidDateRangeException::class);
        $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '',
        ));
    }

    // ── Account existence check ───────────────────────────────────────────────

    public function testUnknownAccountThrowsAccountNotFoundException(): void
    {
        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('getById')
            ->willThrowException(new AccountNotFoundException('not found'));
        $ledger = $this->createStub(LedgerRepositoryInterface::class);

        $handler = new FindAccountStatementHandler($ledger, $accounts, new NullLogger());

        $this->expectException(AccountNotFoundException::class);
        $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-31T23:59:59Z',
        ));
    }

    // ── Happy path: no activity ───────────────────────────────────────────────

    public function testNoActivityReturnsZeroOpeningAndClosingBalance(): void
    {
        $handler = $this->buildHandler(account: $this->makeAccount());

        $dto = $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-31T23:59:59Z',
        ));

        self::assertSame(0, $dto->openingBalanceMinorUnits);
        self::assertSame(0, $dto->closingBalanceMinorUnits);
        self::assertSame([], $dto->movements);
        self::assertSame(0, $dto->totalMovements);
    }

    // ── Opening balance ───────────────────────────────────────────────────────

    public function testOpeningBalanceTakenFromLastEntryBeforeFrom(): void
    {
        $openingEntry = $this->makeLedgerEntry(balanceAfter: 5_000, occurredAt: '2025-12-31T23:59:59Z');

        $ledger = $this->createStub(LedgerRepositoryInterface::class);
        $ledger->method('findLastEntryBefore')->willReturn($openingEntry);
        $ledger->method('findLastEntryAtOrBefore')->willReturn(null);
        $ledger->method('findByAccountIdAndDateRange')
            ->willReturn(new LedgerPage([], 0, 1, 50));

        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('getById')->willReturn($this->makeAccount());

        $handler = new FindAccountStatementHandler($ledger, $accounts, new NullLogger());
        $dto     = $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-31T23:59:59Z',
        ));

        // No movements in range: closing = opening
        self::assertSame(5_000, $dto->openingBalanceMinorUnits);
        self::assertSame(5_000, $dto->closingBalanceMinorUnits);
    }

    public function testZeroOpeningBalanceWhenNoEntryBeforeFrom(): void
    {
        $ledger = $this->createStub(LedgerRepositoryInterface::class);
        $ledger->method('findLastEntryBefore')->willReturn(null);
        $ledger->method('findLastEntryAtOrBefore')->willReturn(null);
        $ledger->method('findByAccountIdAndDateRange')
            ->willReturn(new LedgerPage([], 0, 1, 50));

        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('getById')->willReturn($this->makeAccount());

        $handler = new FindAccountStatementHandler($ledger, $accounts, new NullLogger());
        $dto     = $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-31T23:59:59Z',
        ));

        self::assertSame(0, $dto->openingBalanceMinorUnits);
    }

    // ── Closing balance ───────────────────────────────────────────────────────

    public function testClosingBalanceTakenFromLastEntryAtOrBeforeTo(): void
    {
        $closingEntry = $this->makeLedgerEntry(balanceAfter: 8_500, occurredAt: '2026-01-20T14:00:00Z');
        $movement     = $this->makeLedgerEntry(balanceAfter: 8_500, occurredAt: '2026-01-20T14:00:00Z');

        $ledger = $this->createStub(LedgerRepositoryInterface::class);
        $ledger->method('findLastEntryBefore')->willReturn(null);
        $ledger->method('findLastEntryAtOrBefore')->willReturn($closingEntry);
        $ledger->method('findByAccountIdAndDateRange')
            ->willReturn(new LedgerPage([$movement], 1, 1, 50));

        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('getById')->willReturn($this->makeAccount());

        $handler = new FindAccountStatementHandler($ledger, $accounts, new NullLogger());
        $dto     = $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-31T23:59:59Z',
        ));

        self::assertSame(8_500, $dto->closingBalanceMinorUnits);
        self::assertSame(0,     $dto->openingBalanceMinorUnits);
    }

    public function testClosingEqualsOpeningWhenNoMovementsInRange(): void
    {
        $openingEntry = $this->makeLedgerEntry(balanceAfter: 3_000, occurredAt: '2025-12-01T00:00:00Z');

        $ledger = $this->createStub(LedgerRepositoryInterface::class);
        $ledger->method('findLastEntryBefore')->willReturn($openingEntry);
        $ledger->method('findLastEntryAtOrBefore')->willReturn(null);
        $ledger->method('findByAccountIdAndDateRange')
            ->willReturn(new LedgerPage([], 0, 1, 50));

        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('getById')->willReturn($this->makeAccount());

        $handler = new FindAccountStatementHandler($ledger, $accounts, new NullLogger());
        $dto     = $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-31T23:59:59Z',
        ));

        self::assertSame(3_000, $dto->openingBalanceMinorUnits);
        self::assertSame(3_000, $dto->closingBalanceMinorUnits);
    }

    // ── DTO fields and pagination ─────────────────────────────────────────────

    public function testDtoFieldsArePopulatedCorrectly(): void
    {
        $account = $this->makeAccount(ownerName: 'Alice Smith', currency: 'USD');

        // Use a stub that echoes back the perPage argument so we can verify it
        // flows through to the DTO correctly.
        $ledger = $this->createStub(LedgerRepositoryInterface::class);
        $ledger->method('findLastEntryBefore')->willReturn(null);
        $ledger->method('findLastEntryAtOrBefore')->willReturn(null);
        $ledger->method('findByAccountIdAndDateRange')
            ->willReturnCallback(fn ($id, $from, $to, int $page, int $perPage)
                => new LedgerPage([], 0, $page, $perPage));

        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('getById')->willReturn($account);

        $handler = new FindAccountStatementHandler($ledger, $accounts, new NullLogger());

        $dto = $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-31T23:59:59Z',
            page:      1,
            perPage:   25,
        ));

        self::assertInstanceOf(AccountStatementDTO::class, $dto);
        self::assertSame(self::ACCOUNT_ID, $dto->accountId);
        self::assertSame('Alice Smith',    $dto->ownerName);
        self::assertSame('USD',            $dto->currency);
        self::assertSame(1,                $dto->page);
        self::assertSame(25,               $dto->perPage);
        self::assertSame(1,                $dto->totalPages);
    }

    public function testPaginationMetadataReflectsLedgerPageResult(): void
    {
        $entries = [
            $this->makeLedgerEntry(balanceAfter: 5_000, occurredAt: '2026-01-10T00:00:00Z'),
            $this->makeLedgerEntry(balanceAfter: 6_000, occurredAt: '2026-01-15T00:00:00Z'),
        ];

        $ledger = $this->createStub(LedgerRepositoryInterface::class);
        $ledger->method('findLastEntryBefore')->willReturn(null);
        $ledger->method('findLastEntryAtOrBefore')
            ->willReturn($this->makeLedgerEntry(balanceAfter: 6_000, occurredAt: '2026-01-15T00:00:00Z'));
        $ledger->method('findByAccountIdAndDateRange')
            ->willReturn(new LedgerPage($entries, total: 102, page: 2, perPage: 50));

        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('getById')->willReturn($this->makeAccount());

        $handler = new FindAccountStatementHandler($ledger, $accounts, new NullLogger());
        $dto     = $handler(new FindAccountStatementQuery(
            accountId: self::ACCOUNT_ID,
            from:      '2026-01-01T00:00:00Z',
            to:        '2026-01-31T23:59:59Z',
            page:      2,
            perPage:   50,
        ));

        self::assertSame(102,   $dto->totalMovements);
        self::assertSame(2,     $dto->page);
        self::assertSame(50,    $dto->perPage);
        self::assertSame(3,     $dto->totalPages);     // ceil(102/50) = 3
        self::assertCount(2,    $dto->movements);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build a handler with a clean-slate ledger stub (no prior entries).
     */
    private function buildHandler(Account $account): FindAccountStatementHandler
    {
        $accounts = $this->createStub(AccountRepositoryInterface::class);
        $accounts->method('getById')->willReturn($account);

        $ledger = $this->createStub(LedgerRepositoryInterface::class);
        $ledger->method('findLastEntryBefore')->willReturn(null);
        $ledger->method('findLastEntryAtOrBefore')->willReturn(null);
        $ledger->method('findByAccountIdAndDateRange')
            ->willReturn(new LedgerPage([], 0, 1, 50));

        return new FindAccountStatementHandler($ledger, $accounts, new NullLogger());
    }

    private function makeAccount(
        string $ownerName = 'Test User',
        string $currency  = 'USD',
        int    $balance   = 0,
    ): Account {
        return Account::reconstitute(
            id:        AccountDomainId::fromString(self::ACCOUNT_ID),
            ownerName: $ownerName,
            currency:  $currency,
            balance:   new Balance($balance, $currency),
            status:    AccountStatus::ACTIVE,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            version:   1,
        );
    }

    private function makeLedgerEntry(int $balanceAfter, string $occurredAt): LedgerEntry
    {
        return LedgerEntry::reconstitute(
            id:                     LedgerEntryId::generate(),
            accountId:              LedgerAccountId::fromString(self::ACCOUNT_ID),
            counterpartyAccountId:  LedgerAccountId::fromString(self::OTHER_ID),
            transferId:             self::XFER_ID,
            entryType:              EntryType::CREDIT,
            transferType:           'transfer',
            amountMinorUnits:       1_000,
            currency:               'USD',
            balanceAfterMinorUnits: $balanceAfter,
            occurredAt:             new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')),
            createdAt:              new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
