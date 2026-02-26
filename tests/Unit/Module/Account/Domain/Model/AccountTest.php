<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Account\Domain\Model;

use App\Module\Account\Domain\Event\AccountClosed;
use App\Module\Account\Domain\Event\AccountCreated;
use App\Module\Account\Domain\Event\AccountCredited;
use App\Module\Account\Domain\Event\AccountDebited;
use App\Module\Account\Domain\Event\AccountFrozen;
use App\Module\Account\Domain\Event\AccountUnfrozen;
use App\Module\Account\Domain\Exception\AccountClosedException;
use App\Module\Account\Domain\Exception\AccountFrozenException;
use App\Module\Account\Domain\Exception\CurrencyMismatchException;
use App\Module\Account\Domain\Exception\InsufficientFundsException;
use App\Module\Account\Domain\Exception\InvalidAccountStateException;
use App\Module\Account\Domain\Exception\NonZeroBalanceOnCloseException;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\Model\AccountStatus;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Domain\ValueObject\Balance;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Account aggregate root.
 *
 * Covers:
 *  - open() factory invariants and initial state
 *  - Domain event emission (AccountCreated, AccountDebited, AccountCredited)
 *  - debit() / credit() balance engine
 *  - Currency mismatch guards
 *  - Frozen account guards
 *  - freeze() / unfreeze() status transitions
 *  - Reconstitution (no events)
 *  - Version counter behaviour
 */
final class AccountTest extends TestCase
{
    private AccountId $id;
    private string    $transferId;

    protected function setUp(): void
    {
        $this->id         = AccountId::fromString('aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
        $this->transferId = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
    }

    // ── open(): factory ──────────────────────────────────────────────────────

    public function testOpenCreatesActiveAccountWithZeroBalance(): void
    {
        $account = Account::open($this->id, 'Alice Smith', 'USD');

        self::assertSame(AccountStatus::ACTIVE, $account->getStatus());
        self::assertTrue($account->getBalance()->isZero());
        self::assertSame('USD', $account->getCurrency());
        self::assertSame('Alice Smith', $account->getOwnerName());
        self::assertSame(0, $account->getVersion());
    }

    public function testOpenWithInitialBalance(): void
    {
        $account = Account::open($this->id, 'Bob Jones', 'EUR', 50000);

        self::assertSame(50000, $account->getBalance()->getAmountMinorUnits());
        self::assertSame('EUR', $account->getCurrency());
    }

    public function testOpenRaisesAccountCreatedEvent(): void
    {
        $account = Account::open($this->id, 'Alice Smith', 'USD', 1000);
        $events  = $account->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(AccountCreated::class, $events[0]);
        self::assertTrue($events[0]->accountId->equals($this->id));
        self::assertSame('Alice Smith', $events[0]->ownerName);
        self::assertSame(1000, $events[0]->initialBalance->getAmountMinorUnits());
    }

    public function testOpenWithBlankOwnerNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('owner name must not be blank');

        Account::open($this->id, '   ', 'USD');
    }

    public function testOpenWithInvalidCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Account::open($this->id, 'Alice', 'us');
    }

    public function testOpenWithNegativeInitialBalanceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');

        Account::open($this->id, 'Alice', 'USD', -100);
    }

    // ── debit() ──────────────────────────────────────────────────────────────

    public function testDebitReducesBalance(): void
    {
        $account = $this->activeAccount(10000);
        $account->debit(new Balance(3000, 'USD'), $this->transferId);

        self::assertSame(7000, $account->getBalance()->getAmountMinorUnits());
    }

    public function testDebitRaisesAccountDebitedEvent(): void
    {
        $account = $this->activeAccount(10000);
        $account->releaseEvents(); // drain AccountCreated
        $account->debit(new Balance(3000, 'USD'), $this->transferId);

        $events = $account->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AccountDebited::class, $events[0]);
        self::assertSame(3000, $events[0]->amount->getAmountMinorUnits());
        self::assertSame(7000, $events[0]->balanceAfter->getAmountMinorUnits());
        self::assertSame($this->transferId, $events[0]->transferId);
        self::assertSame('transfer', $events[0]->transferType);
    }

    public function testDebitWithReversalTypeEmitsCorrectTransferType(): void
    {
        $account = $this->activeAccount(10000);
        $account->releaseEvents();
        $account->debit(new Balance(1000, 'USD'), $this->transferId, 'reversal');

        $events = $account->releaseEvents();
        self::assertInstanceOf(AccountDebited::class, $events[0]);
        self::assertSame('reversal', $events[0]->transferType);
    }

    public function testDebitIncrementsVersion(): void
    {
        $account = $this->activeAccount(10000);
        $account->debit(new Balance(1000, 'USD'), $this->transferId);

        self::assertSame(1, $account->getVersion());
    }

    public function testDebitThrowsInsufficientFundsWhenBalanceTooLow(): void
    {
        $this->expectException(InsufficientFundsException::class);

        $account = $this->activeAccount(500);
        $account->debit(new Balance(1000, 'USD'), $this->transferId);
    }

    public function testDebitThrowsCurrencyMismatch(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $account = $this->activeAccount(10000); // USD account
        $account->debit(new Balance(1000, 'EUR'), $this->transferId);
    }

    public function testDebitThrowsAccountFrozenWhenFrozen(): void
    {
        $this->expectException(AccountFrozenException::class);

        $account = $this->activeAccount(10000);
        $account->freeze();
        $account->debit(new Balance(1000, 'USD'), $this->transferId);
    }

    // ── credit() ─────────────────────────────────────────────────────────────

    public function testCreditIncreasesBalance(): void
    {
        $account = $this->activeAccount(5000);
        $account->credit(new Balance(2000, 'USD'), $this->transferId);

        self::assertSame(7000, $account->getBalance()->getAmountMinorUnits());
    }

    public function testCreditRaisesAccountCreditedEvent(): void
    {
        $account = $this->activeAccount(5000);
        $account->releaseEvents(); // drain AccountCreated
        $account->credit(new Balance(2000, 'USD'), $this->transferId);

        $events = $account->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AccountCredited::class, $events[0]);
        self::assertSame(2000, $events[0]->amount->getAmountMinorUnits());
        self::assertSame(7000, $events[0]->balanceAfter->getAmountMinorUnits());
        self::assertSame('transfer', $events[0]->transferType);
    }

    public function testCreditWithReversalTypeEmitsCorrectTransferType(): void
    {
        $account = $this->activeAccount(0);
        $account->releaseEvents();
        $account->credit(new Balance(1000, 'USD'), $this->transferId, 'reversal');

        $events = $account->releaseEvents();
        self::assertInstanceOf(AccountCredited::class, $events[0]);
        self::assertSame('reversal', $events[0]->transferType);
    }

    public function testCreditIncrementsVersion(): void
    {
        $account = $this->activeAccount(0);
        $account->credit(new Balance(500, 'USD'), $this->transferId);

        self::assertSame(1, $account->getVersion());
    }

    public function testCreditThrowsCurrencyMismatch(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $account = $this->activeAccount(0);
        $account->credit(new Balance(1000, 'EUR'), $this->transferId);
    }

    public function testCreditThrowsAccountFrozenWhenFrozen(): void
    {
        $this->expectException(AccountFrozenException::class);

        $account = $this->activeAccount(0);
        $account->freeze();
        $account->credit(new Balance(1000, 'USD'), $this->transferId);
    }

    // ── freeze / unfreeze ────────────────────────────────────────────────────

    public function testFreezeTransitionsToFrozen(): void
    {
        $account = $this->activeAccount(0);
        $account->freeze();

        self::assertSame(AccountStatus::FROZEN, $account->getStatus());
    }

    public function testUnfreezeTransitionsToActive(): void
    {
        $account = $this->activeAccount(0);
        $account->freeze();
        $account->unfreeze();

        self::assertSame(AccountStatus::ACTIVE, $account->getStatus());
    }

    public function testFreezeThrowsWhenAlreadyFrozen(): void
    {
        $this->expectException(InvalidAccountStateException::class);
        $this->expectExceptionMessage('frozen');

        $account = $this->activeAccount(0);
        $account->freeze();
        $account->freeze(); // second freeze must throw
    }

    public function testUnfreezeThrowsWhenAlreadyActive(): void
    {
        $this->expectException(InvalidAccountStateException::class);
        $this->expectExceptionMessage('active');

        $account = $this->activeAccount(0);
        $account->unfreeze(); // not frozen → must throw
    }

    public function testFreezeRaisesAccountFrozenEvent(): void
    {
        $account = $this->activeAccount(0);
        $account->releaseEvents(); // drain AccountCreated

        $account->freeze();

        $events = $account->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AccountFrozen::class, $events[0]);
        self::assertTrue($events[0]->accountId->equals($this->id));
    }

    public function testUnfreezeRaisesAccountUnfrozenEvent(): void
    {
        $account = $this->activeAccount(0);
        $account->freeze();
        $account->releaseEvents(); // drain AccountCreated + AccountFrozen

        $account->unfreeze();

        $events = $account->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AccountUnfrozen::class, $events[0]);
        self::assertTrue($events[0]->accountId->equals($this->id));
    }

    public function testFreezeIncrementsVersion(): void
    {
        $account = $this->activeAccount(0);
        $account->freeze();

        self::assertSame(1, $account->getVersion());
    }

    public function testUnfreezeIncrementsVersion(): void
    {
        $account = $this->activeAccount(0);
        $account->freeze();  // version → 1
        $account->unfreeze(); // version → 2

        self::assertSame(2, $account->getVersion());
    }

    // ── releaseEvents() ──────────────────────────────────────────────────────

    public function testReleaseEventsClearsQueue(): void
    {
        $account = $this->activeAccount(0);
        $account->releaseEvents();

        self::assertEmpty($account->releaseEvents());
    }

    // ── reconstitute() ───────────────────────────────────────────────────────

    public function testReconstituteDoesNotRaiseEvents(): void
    {
        $account = Account::reconstitute(
            id:        $this->id,
            ownerName: 'Alice',
            currency:  'USD',
            balance:   new Balance(5000, 'USD'),
            status:    AccountStatus::ACTIVE,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            version:   3,
        );

        self::assertEmpty($account->releaseEvents());
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $created = new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC'));
        $updated = new \DateTimeImmutable('2025-06-01 12:00:00', new \DateTimeZone('UTC'));

        $account = Account::reconstitute(
            id:        $this->id,
            ownerName: 'Bob',
            currency:  'EUR',
            balance:   new Balance(99900, 'EUR'),
            status:    AccountStatus::FROZEN,
            createdAt: $created,
            updatedAt: $updated,
            version:   7,
        );

        self::assertTrue($account->getId()->equals($this->id));
        self::assertSame('Bob', $account->getOwnerName());
        self::assertSame('EUR', $account->getCurrency());
        self::assertSame(99900, $account->getBalance()->getAmountMinorUnits());
        self::assertSame(AccountStatus::FROZEN, $account->getStatus());
        self::assertEquals($created, $account->getCreatedAt());
        self::assertEquals($updated, $account->getUpdatedAt());
        self::assertSame(7, $account->getVersion());
    }

    // ── Version ─────────────────────────────────────────────────────────────

    public function testEachDebitAndCreditIncrementsVersionByOne(): void
    {
        $account = $this->activeAccount(10000);
        // version 0 after open

        $account->debit(new Balance(1000, 'USD'), $this->transferId);   // → 1
        self::assertSame(1, $account->getVersion());

        $account->credit(new Balance(500, 'USD'), $this->transferId);   // → 2
        self::assertSame(2, $account->getVersion());
    }

    // ── reconstitute() currency guard ────────────────────────────────────────

    public function testReconstituteThrowsOnBalanceCurrencyMismatch(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('balance currency');

        Account::reconstitute(
            id:        $this->id,
            ownerName: 'Alice',
            currency:  'USD',
            balance:   new Balance(5000, 'EUR'), // ← mismatch: account is USD, balance is EUR
            status:    AccountStatus::ACTIVE,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            version:   0,
        );
    }

    // ── debit/credit on a CLOSED-status account ──────────────────────────────
    // CLOSED accounts cannot be debited or credited; we test via reconstitute() with status=CLOSED.

    public function testDebitOnClosedAccountThrowsAccountClosedException(): void
    {
        $this->expectException(AccountClosedException::class);
        $this->expectExceptionMessage('permanently closed');

        $account = Account::reconstitute(
            id:        $this->id,
            ownerName: 'Alice',
            currency:  'USD',
            balance:   new Balance(5000, 'USD'),
            status:    AccountStatus::CLOSED,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            version:   0,
        );
        $account->debit(new Balance(1000, 'USD'), $this->transferId);
    }

    public function testCreditOnClosedAccountThrowsAccountClosedException(): void
    {
        $this->expectException(AccountClosedException::class);
        $this->expectExceptionMessage('permanently closed');

        $account = Account::reconstitute(
            id:        $this->id,
            ownerName: 'Alice',
            currency:  'USD',
            balance:   new Balance(0, 'USD'),
            status:    AccountStatus::CLOSED,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            version:   0,
        );
        $account->credit(new Balance(500, 'USD'), $this->transferId);
    }

    public function testDebitOnFrozenAccountThrowsAccountFrozenException(): void
    {
        $this->expectException(AccountFrozenException::class);
        $this->expectExceptionMessage('frozen');

        $account = $this->activeAccount(5000);
        $account->freeze();
        $account->debit(new Balance(1000, 'USD'), $this->transferId);
    }

    public function testFreezeOnClosedAccountThrowsInvalidAccountStateException(): void
    {
        $this->expectException(InvalidAccountStateException::class);

        $account = Account::reconstitute(
            id:        $this->id,
            ownerName: 'Alice',
            currency:  'USD',
            balance:   new Balance(0, 'USD'),
            status:    AccountStatus::CLOSED,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            version:   0,
        );
        $account->freeze();
    }

    // ── ownerName validation ─────────────────────────────────────────────────

    public function testOpenWithBlankOwnerNameThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('blank');

        Account::open($this->id, '   ', 'USD');
    }

    public function testOpenWithOwnerNameExceeding255CharactersThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('255');

        Account::open($this->id, str_repeat('A', 256), 'USD');
    }

    public function testOpenWithExactly255CharacterNameSucceeds(): void
    {
        $account = Account::open($this->id, str_repeat('A', 255), 'USD');

        self::assertSame(str_repeat('A', 255), $account->getOwnerName());
    }

    // ── close() ──────────────────────────────────────────────────────────────

    public function testCloseTransitionsActiveAccountToClosed(): void
    {
        $account = $this->activeAccount(0);
        $account->close();

        self::assertSame(AccountStatus::CLOSED, $account->getStatus());
    }

    public function testCloseWithNonZeroBalanceThrowsNonZeroBalanceOnCloseException(): void
    {
        $this->expectException(NonZeroBalanceOnCloseException::class);
        $this->expectExceptionMessage('5000');

        $account = $this->activeAccount(5000);
        $account->close();
    }

    public function testCloseFrozenAccountTransitionsToClosed(): void
    {
        $account = $this->activeAccount(0);
        $account->freeze();
        $account->releaseEvents(); // clear frozen event
        $account->close();

        self::assertSame(AccountStatus::CLOSED, $account->getStatus());
    }

    public function testCloseRaisesExactlyOneAccountClosedEvent(): void
    {
        $account = $this->activeAccount(0);
        $account->releaseEvents(); // clear AccountCreated
        $account->close();

        $events = $account->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(AccountClosed::class, $events[0]);
    }

    public function testAccountClosedEventCarriesCorrectAccountId(): void
    {
        $account = $this->activeAccount(0);
        $account->releaseEvents();
        $account->close();

        $events = $account->releaseEvents();

        self::assertInstanceOf(AccountClosed::class, $events[0]);
        self::assertTrue($events[0]->accountId->equals($this->id));
    }

    public function testCloseIncrementsVersion(): void
    {
        $account = $this->activeAccount(0);
        $versionBefore = $account->getVersion();
        $account->close();

        self::assertSame($versionBefore + 1, $account->getVersion());
    }

    public function testCloseAlreadyClosedAccountThrowsInvalidAccountStateException(): void
    {
        $this->expectException(InvalidAccountStateException::class);
        $this->expectExceptionMessage('closed');

        $account = Account::reconstitute(
            id:        $this->id,
            ownerName: 'Alice',
            currency:  'USD',
            balance:   new Balance(0, 'USD'),
            status:    AccountStatus::CLOSED,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            version:   1,
        );
        $account->close();
    }

    private function activeAccount(int $balanceMinorUnits): Account
    {
        return Account::open($this->id, 'Test Owner', 'USD', $balanceMinorUnits);
    }
}
