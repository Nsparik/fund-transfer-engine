<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Account\Application\Service;

use App\Module\Account\Application\Service\AccountTransferService;
use App\Module\Account\Domain\Exception\AccountFrozenException;
use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Exception\InsufficientFundsException;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\Model\AccountStatus;
use App\Module\Account\Domain\Repository\AccountRepositoryInterface;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Domain\ValueObject\Balance;
use App\Shared\Domain\Exception\AccountNotFoundForTransferException;
use App\Shared\Domain\Exception\AccountRuleViolationException;
use App\Shared\Application\Port\TaggedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AccountTransferService.
 *
 * AccountTransferService is the Account module's implementation of
 * AccountTransferPort.  These tests verify:
 *  - Happy path: debit source / credit dest, both saved, events returned
 *  - Lock order: alphabetical UUID ordering prevents AB/BA deadlocks
 *  - AccountNotFoundException wrapping → AccountNotFoundForTransferException
 *  - AccountFrozenException wrapping    → AccountRuleViolationException
 *  - InsufficientFundsException wrapping → AccountRuleViolationException
 *  - Correct domain code delegation via getDomainCode()
 */
final class AccountTransferServiceTest extends TestCase
{
    // UUIDs chosen so that 'aaa...' < 'bbb...' alphabetically — affects lock order tests.
    private const SOURCE_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const DEST_ID   = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const XFER_ID   = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    // ── Happy path ─────────────────────────────────────────────────────────────

    public function testExecuteDoubleEntryDebitsSourceAndCreditsDestination(): void
    {
        $sourceId = AccountId::fromString(self::SOURCE_ID);
        $destId   = AccountId::fromString(self::DEST_ID);

        $source = $this->activeAccount($sourceId, 5_000);
        $dest   = $this->activeAccount($destId, 1_000);

        $savedAccounts = [];

        $repo = $this->createMock(AccountRepositoryInterface::class);
        $repo->method('getByIdForUpdate')->willReturnCallback(
            function (AccountId $id) use ($sourceId, $source, $destId, $dest): Account {
                return $id->toString() === $sourceId->toString() ? $source : $dest;
            }
        );
        $repo->method('save')->willReturnCallback(
            function (Account $a) use (&$savedAccounts): void {
                $savedAccounts[] = $a;
            }
        );

        $service = new AccountTransferService($repo);
        $result  = $service->executeDoubleEntry(
            sourceAccountId:      self::SOURCE_ID,
            destinationAccountId: self::DEST_ID,
            amountMinorUnits:     2_000,
            currency:             'USD',
            transferId:           self::XFER_ID,
        );

        // Source debited: 5000 − 2000 = 3000
        self::assertSame(3_000, $source->getBalance()->getAmountMinorUnits());
        // Destination credited: 1000 + 2000 = 3000
        self::assertSame(3_000, $dest->getBalance()->getAmountMinorUnits());

        // Both accounts were saved.
        self::assertCount(2, $savedAccounts);

        // Events from both aggregates are returned as TaggedEvent instances.
        self::assertNotEmpty($result->events);
        self::assertInstanceOf(TaggedEvent::class, $result->events[0]);
        // Source event is tagged with sourceAccountId, dest event with destinationAccountId.
        self::assertSame(self::SOURCE_ID, $result->events[0]->aggregateId, 'Source event must use sourceAccountId');
        self::assertSame(self::DEST_ID,   $result->events[1]->aggregateId, 'Dest event must use destinationAccountId');
        // Balance snapshots are populated.
        self::assertSame(3_000, $result->sourceBalanceAfterMinorUnits);
        self::assertSame(3_000, $result->destinationBalanceAfterMinorUnits);
    }

    // ── Lock order ─────────────────────────────────────────────────────────────

    public function testLockOrderIsAlphabeticalByUuid(): void
    {
        $sourceId = AccountId::fromString(self::SOURCE_ID); // 'aaa...'
        $destId   = AccountId::fromString(self::DEST_ID);   // 'bbb...'

        $source = $this->activeAccount($sourceId, 5_000);
        $dest   = $this->activeAccount($destId, 0);

        $lockOrder = [];

        $repo = $this->createMock(AccountRepositoryInterface::class);
        $repo->method('getByIdForUpdate')->willReturnCallback(
            function (AccountId $id) use ($source, $dest, &$lockOrder): Account {
                $lockOrder[] = $id->toString();
                return $id->toString() === self::SOURCE_ID ? $source : $dest;
            }
        );
        // save() is void — no configuration needed.

        $service = new AccountTransferService($repo);
        $service->executeDoubleEntry(
            sourceAccountId:      self::SOURCE_ID,
            destinationAccountId: self::DEST_ID,
            amountMinorUnits:     1_000,
            currency:             'USD',
            transferId:           self::XFER_ID,
        );

        // 'aaa...' < 'bbb...' — source is locked first.
        self::assertSame([self::SOURCE_ID, self::DEST_ID], $lockOrder);
    }

    public function testLockOrderIsAlphabeticalWhenDestinationSortsFirst(): void
    {
        // DEST ('bbb') < SOURCE ('ccc') — dest should be locked first.
        $sourceId = AccountId::fromString('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
        $destId   = AccountId::fromString('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

        $source = $this->activeAccount($sourceId, 5_000);
        $dest   = $this->activeAccount($destId, 0);

        $lockOrder = [];

        $repo = $this->createMock(AccountRepositoryInterface::class);
        $repo->method('getByIdForUpdate')->willReturnCallback(
            function (AccountId $id) use ($source, $dest, &$lockOrder): Account {
                $lockOrder[] = $id->toString();
                return $id->toString() === 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' ? $source : $dest;
            }
        );
        // save() is void — no configuration needed.

        $service = new AccountTransferService($repo);
        $service->executeDoubleEntry(
            sourceAccountId:      'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            destinationAccountId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            amountMinorUnits:     1_000,
            currency:             'USD',
            transferId:           self::XFER_ID,
        );

        // 'bbb...' < 'ccc...' — destination is locked first.
        self::assertSame(['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'], $lockOrder);
    }

    // ── AccountNotFoundException wrapping ──────────────────────────────────────

    public function testAccountNotFoundWrappedInAccountNotFoundForTransferException(): void
    {
        $repo = $this->createStub(AccountRepositoryInterface::class);
        $repo->method('getByIdForUpdate')
            ->willThrowException(new AccountNotFoundException('Account not found'));

        $service = new AccountTransferService($repo);

        $this->expectException(AccountNotFoundForTransferException::class);

        $service->executeDoubleEntry(
            sourceAccountId:      self::SOURCE_ID,
            destinationAccountId: self::DEST_ID,
            amountMinorUnits:     1_000,
            currency:             'USD',
            transferId:           self::XFER_ID,
        );
    }

    public function testAccountNotFoundDelegatesDomainCode(): void
    {
        $repo = $this->createStub(AccountRepositoryInterface::class);
        $repo->method('getByIdForUpdate')
            ->willThrowException(new AccountNotFoundException('Account not found'));

        $service = new AccountTransferService($repo);

        try {
            $service->executeDoubleEntry(
                sourceAccountId:      self::SOURCE_ID,
                destinationAccountId: self::DEST_ID,
                amountMinorUnits:     1_000,
                currency:             'USD',
                transferId:           self::XFER_ID,
            );
            self::fail('Expected AccountNotFoundForTransferException');
        } catch (AccountNotFoundForTransferException $e) {
            self::assertSame('ACCOUNT_NOT_FOUND', $e->getDomainCode());
        }
    }

    // ── AccountDomainException wrapping ────────────────────────────────────────

    public function testFrozenSourceAccountWrappedInAccountRuleViolationException(): void
    {
        $sourceId = AccountId::fromString(self::SOURCE_ID);
        $destId   = AccountId::fromString(self::DEST_ID);

        $source = $this->frozenAccount($sourceId, 5_000);
        $dest   = $this->activeAccount($destId, 0);

        $repo = $this->createMock(AccountRepositoryInterface::class);
        $repo->method('getByIdForUpdate')->willReturnCallback(
            function (AccountId $id) use ($sourceId, $source, $destId, $dest): Account {
                return $id->toString() === $sourceId->toString() ? $source : $dest;
            }
        );

        $service = new AccountTransferService($repo);

        $this->expectException(AccountRuleViolationException::class);

        $service->executeDoubleEntry(
            sourceAccountId:      self::SOURCE_ID,
            destinationAccountId: self::DEST_ID,
            amountMinorUnits:     1_000,
            currency:             'USD',
            transferId:           self::XFER_ID,
        );
    }

    public function testInsufficientFundsWrappedInAccountRuleViolationException(): void
    {
        $sourceId = AccountId::fromString(self::SOURCE_ID);
        $destId   = AccountId::fromString(self::DEST_ID);

        $source = $this->activeAccount($sourceId, 0); // zero balance — debit will fail
        $dest   = $this->activeAccount($destId, 0);

        $repo = $this->createMock(AccountRepositoryInterface::class);
        $repo->method('getByIdForUpdate')->willReturnCallback(
            function (AccountId $id) use ($sourceId, $source, $destId, $dest): Account {
                return $id->toString() === $sourceId->toString() ? $source : $dest;
            }
        );

        $service = new AccountTransferService($repo);

        $this->expectException(AccountRuleViolationException::class);

        $service->executeDoubleEntry(
            sourceAccountId:      self::SOURCE_ID,
            destinationAccountId: self::DEST_ID,
            amountMinorUnits:     1_000,
            currency:             'USD',
            transferId:           self::XFER_ID,
        );
    }

    public function testAccountRuleViolationDelegatesDomainCode(): void
    {
        $sourceId = AccountId::fromString(self::SOURCE_ID);
        $destId   = AccountId::fromString(self::DEST_ID);

        $source = $this->frozenAccount($sourceId, 5_000);
        $dest   = $this->activeAccount($destId, 0);

        $repo = $this->createMock(AccountRepositoryInterface::class);
        $repo->method('getByIdForUpdate')->willReturnCallback(
            function (AccountId $id) use ($sourceId, $source, $destId, $dest): Account {
                return $id->toString() === $sourceId->toString() ? $source : $dest;
            }
        );

        $service = new AccountTransferService($repo);

        try {
            $service->executeDoubleEntry(
                sourceAccountId:      self::SOURCE_ID,
                destinationAccountId: self::DEST_ID,
                amountMinorUnits:     1_000,
                currency:             'USD',
                transferId:           self::XFER_ID,
            );
            self::fail('Expected AccountRuleViolationException');
        } catch (AccountRuleViolationException $e) {
            self::assertSame('ACCOUNT_FROZEN', $e->getDomainCode());
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function activeAccount(AccountId $id, int $balanceMinorUnits): Account
    {
        return Account::reconstitute(
            id:        $id,
            ownerName: 'Test Owner',
            currency:  'USD',
            balance:   new Balance($balanceMinorUnits, 'USD'),
            status:    AccountStatus::ACTIVE,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            version:   1,
        );
    }

    private function frozenAccount(AccountId $id, int $balanceMinorUnits): Account
    {
        return Account::reconstitute(
            id:        $id,
            ownerName: 'Test Owner',
            currency:  'USD',
            balance:   new Balance($balanceMinorUnits, 'USD'),
            status:    AccountStatus::FROZEN,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            version:   1,
        );
    }
}
