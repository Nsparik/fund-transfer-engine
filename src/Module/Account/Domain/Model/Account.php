<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Model;

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
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Domain\ValueObject\Balance;

/**
 * Account — Aggregate Root of the Account bounded context.
 *
 * ## Invariants
 *   1. Balance can never go negative.
 *   2. Debit and credit amounts must match the account's currency.
 *   3. Frozen accounts cannot be debited or credited.
 *   4. Closed accounts cannot be debited, credited, frozen, or unfrozen.
 *
 * ## Balance engine
 *   Balance arithmetic is delegated to the Balance value object.
 *   Balance::subtract() throws InsufficientFundsException when the result
 *   would be negative — the aggregate does not need to re-check.
 *
 * ## Domain events
 *   Collected in $domainEvents, released via releaseEvents() after persistence.
 *   The reconstitute() path does NOT raise events.
 *
 * ## Concurrency
 *   The version field is incremented on every balance mutation and persisted
 *   by the repository's upsert.  The transfer handler uses pessimistic
 *   SELECT … FOR UPDATE locking via getByIdForUpdate() to prevent concurrent
 *   debit/credit races without needing a version-guard WHERE clause.
 */
final class Account
{
    private AccountStatus $status;
    private \DateTimeImmutable $updatedAt;
    private int $version;
    private ?\DateTimeImmutable $closedAt = null;

    /** @var list<object> */
    private array $domainEvents = [];

    private function __construct(
        private readonly AccountId         $id,
        private readonly string            $ownerName,
        private readonly string            $currency,
        private Balance                    $balance,
        private readonly \DateTimeImmutable $createdAt,
    ) {}


    /**
     * Open a new Account with an initial balance (may be zero).
     *
     * @throws \InvalidArgumentException on invalid currency or negative initial balance
     */
    public static function open(
        AccountId $id,
        string    $ownerName,
        string    $currency,
        int       $initialBalanceMinorUnits = 0,
    ): self {
        if (trim($ownerName) === '') {
            throw new \InvalidArgumentException('Account owner name must not be blank.');
        }

        if (strlen($ownerName) > 255) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Account owner name must not exceed 255 characters, got %d.',
                    strlen($ownerName),
                )
            );
        }

        $now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $balance = new Balance($initialBalanceMinorUnits, $currency);

        $account = new self($id, trim($ownerName), $currency, $balance, $now);
        $account->status    = AccountStatus::ACTIVE;
        $account->updatedAt = $now;
        $account->version   = 0;

        $account->record(new AccountCreated(
            accountId:      $id,
            ownerName:      $account->ownerName,
            initialBalance: $balance,
            occurredAt:     $now,
        ));

        return $account;
    }

    /**
     * Rebuild an Account from a persistence row.  No events are raised.
     */
    public static function reconstitute(
        AccountId         $id,
        string            $ownerName,
        string            $currency,
        Balance           $balance,
        AccountStatus     $status,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int               $version,
        ?\DateTimeImmutable $closedAt = null,
    ): self {
        // Guard: balance currency must match the account's declared currency.
        // A mismatch would indicate a hydration bug or direct DB manipulation —
        // catching it here prevents silent inconsistency from propagating.
        if ($balance->getCurrency() !== $currency) {
            throw new \LogicException(sprintf(
                'Cannot reconstitute account "%s": balance currency "%s" does not match '
                . 'account currency "%s". This indicates a persistence inconsistency.',
                $id->toString(),
                $balance->getCurrency(),
                $currency,
            ));
        }

        $account          = new self($id, $ownerName, $currency, $balance, $createdAt);
        $account->status   = $status;
        $account->updatedAt = $updatedAt;
        $account->version   = $version;
        $account->closedAt  = $closedAt;

        return $account;
    }


    /**
     * Debit (subtract) the given amount from this account's balance.
     *
     * @param Balance $amount                 Amount to debit
     * @param string  $transferId             The initiating transfer's UUID (for event correlation)
     * @param string  $transferType           'transfer' (original) or 'reversal' — for ledger consumers
     * @param string  $counterpartyAccountId  The other account in the transfer (for ledger/statement consumers)
     *
     * @throws AccountFrozenException      when account is not ACTIVE
     * @throws CurrencyMismatchException   when $amount currency ≠ account currency
     * @throws InsufficientFundsException  when balance would go negative
     */
    public function debit(Balance $amount, string $transferId, string $transferType = 'transfer', string $counterpartyAccountId = ''): void
    {
        $this->assertActive();
        $this->assertCurrencyMatches($amount);

        $balanceBefore   = $this->balance;
        $this->balance   = $balanceBefore->subtract($amount);
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->version++;

        $this->record(new AccountDebited(
            accountId:              $this->id,
            amount:                 $amount,
            balanceAfter:           $this->balance,
            transferId:             $transferId,
            transferType:           $transferType,
            counterpartyAccountId:  $counterpartyAccountId,
            occurredAt:             $this->updatedAt,
        ));
    }

    /**
     * Credit (add) the given amount to this account's balance.
     *
     * @param Balance $amount                 Amount to credit
     * @param string  $transferId             The initiating transfer's UUID (for event correlation)
     * @param string  $transferType           'transfer' (original) or 'reversal' — for ledger consumers
     * @param string  $counterpartyAccountId  The other account in the transfer (for ledger/statement consumers)
     *
     * @throws AccountFrozenException    when account is not ACTIVE
     * @throws CurrencyMismatchException when $amount currency ≠ account currency
     */
    public function credit(Balance $amount, string $transferId, string $transferType = 'transfer', string $counterpartyAccountId = ''): void
    {
        $this->assertActive();
        $this->assertCurrencyMatches($amount);

        $this->balance   = $this->balance->add($amount);
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->version++;

        $this->record(new AccountCredited(
            accountId:              $this->id,
            amount:                 $amount,
            balanceAfter:           $this->balance,
            transferId:             $transferId,
            transferType:           $transferType,
            counterpartyAccountId:  $counterpartyAccountId,
            occurredAt:             $this->updatedAt,
        ));
    }


    /**
     * Freeze this account (ACTIVE → FROZEN).
     *
     * @throws InvalidAccountStateException when the account is already FROZEN or CLOSED
     */
    public function freeze(): void
    {
        if (!$this->status->canTransitionTo(AccountStatus::FROZEN)) {
            throw new InvalidAccountStateException(sprintf(
                'Cannot freeze account "%s": current status is "%s". '
                . 'Only ACTIVE accounts can be frozen.',
                $this->id->toString(),
                $this->status->value,
            ));
        }

        $this->status    = AccountStatus::FROZEN;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->version++;

        $this->record(new AccountFrozen(
            accountId:  $this->id,
            occurredAt: $this->updatedAt,
        ));
    }

    /**
     * Unfreeze this account (FROZEN → ACTIVE).
     *
     * @throws InvalidAccountStateException when the account is not FROZEN (already ACTIVE or CLOSED)
     */
    public function unfreeze(): void
    {
        if (!$this->status->canTransitionTo(AccountStatus::ACTIVE)) {
            throw new InvalidAccountStateException(sprintf(
                'Cannot unfreeze account "%s": current status is "%s". '
                . 'Only FROZEN accounts can be unfrozen.',
                $this->id->toString(),
                $this->status->value,
            ));
        }

        $this->status    = AccountStatus::ACTIVE;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->version++;

        $this->record(new AccountUnfrozen(
            accountId:  $this->id,
            occurredAt: $this->updatedAt,
        ));
    }

    /**
     * Close this account permanently (ACTIVE → CLOSED or FROZEN → CLOSED).
     *
     * CLOSED is terminal — this transition cannot be reversed.
     * After closing, the account cannot be debited, credited, frozen, or unfrozen.
     *
     * ## Balance invariant
     *   The account balance MUST be zero before closing.  Closing a funded
     *   account leaves funds in a terminal state — a financial data-integrity
     *   violation.  Drain the balance via a transfer first.
     *
     * @throws NonZeroBalanceOnCloseException when balance > 0
     * @throws InvalidAccountStateException when the account is already CLOSED
     */
    public function close(): void
    {
        if (!$this->status->canTransitionTo(AccountStatus::CLOSED)) {
            throw new InvalidAccountStateException(sprintf(
                'Cannot close account "%s": current status is "%s". '
                . 'Only ACTIVE or FROZEN accounts can be closed.',
                $this->id->toString(),
                $this->status->value,
            ));
        }

        if ($this->balance->getAmountMinorUnits() !== 0) {
            throw new NonZeroBalanceOnCloseException(sprintf(
                'Cannot close account "%s": balance is %d %s (minor units). '
                . 'Transfer the remaining balance to another account before closing.',
                $this->id->toString(),
                $this->balance->getAmountMinorUnits(),
                $this->balance->getCurrency(),
            ));
        }

        $this->status    = AccountStatus::CLOSED;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->closedAt  = $this->updatedAt;
        $this->version++;

        $this->record(new AccountClosed(
            accountId:  $this->id,
            occurredAt: $this->updatedAt,
        ));
    }


    /**
     * Return pending domain events WITHOUT clearing them.
     *
     * Use this inside a database transaction to write events to the outbox
     * atomically, before calling releaseEvents() post-commit to dispatch them
     * synchronously (in-process consumers).
     *
     * @return list<object>
     */
    public function peekEvents(): array
    {
        return $this->domainEvents;
    }

    public function releaseEvents(): array
    {
        $events           = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }


    public function getId(): AccountId
    {
        return $this->id;
    }

    public function getOwnerName(): string
    {
        return $this->ownerName;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalance(): Balance
    {
        return $this->balance;
    }

    public function getStatus(): AccountStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }


    private function assertActive(): void
    {
        if ($this->status === AccountStatus::ACTIVE) {
            return;
        }

        if ($this->status === AccountStatus::CLOSED) {
            throw new AccountClosedException(sprintf(
                'Account "%s" is permanently closed and cannot be debited or credited. '
                . 'Closed accounts are terminal — contact support if this is unexpected.',
                $this->id->toString(),
            ));
        }

        throw new AccountFrozenException(sprintf(
            'Account "%s" is frozen and cannot be debited or credited. '
            . 'Contact support to unfreeze the account.',
            $this->id->toString(),
        ));
    }

    private function assertCurrencyMatches(Balance $amount): void
    {
        if ($amount->getCurrency() !== $this->currency) {
            throw new CurrencyMismatchException(sprintf(
                'Currency mismatch: account "%s" is denominated in %s, '
                . 'but the requested amount is in %s.',
                $this->id->toString(),
                $this->currency,
                $amount->getCurrency(),
            ));
        }
    }

    private function record(object $event): void
    {
        $this->domainEvents[] = $event;
    }
}
