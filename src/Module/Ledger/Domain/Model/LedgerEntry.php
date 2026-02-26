<?php

declare(strict_types=1);

namespace App\Module\Ledger\Domain\Model;

use App\Module\Ledger\Domain\ValueObject\AccountId;
use App\Module\Ledger\Domain\ValueObject\EntryType;
use App\Module\Ledger\Domain\ValueObject\LedgerEntryId;

/**
 * LedgerEntry — the immutable record of one side of a double-entry movement.
 *
 * ## Double-entry invariant
 *   Every completed transfer (and every reversal) produces EXACTLY two
 *   LedgerEntry rows:
 *     1. A DEBIT on the source account   (funds leaving)
 *     2. A CREDIT on the destination account  (funds arriving)
 *
 *   For a REVERSAL, the sides are swapped:
 *     1. A CREDIT on the original source      (refund — funds returned)
 *     2. A DEBIT  on the original destination (reclaim — funds taken back)
 *
 * ## Immutability
 *   Once created, a LedgerEntry is never mutated.  It has no state-machine,
 *   no update methods, and no domain events.  Corrections are made by
 *   inserting new reversal entries — never by updating existing rows.
 *
 * ## balanceAfterMinorUnits
 *   A snapshot of the account balance IMMEDIATELY AFTER this entry was applied.
 *   Stored denormalised for O(1) balance-at-date lookups: to find the balance
 *   at any point in time, find the last LedgerEntry with occurred_at <= T and
 *   read balanceAfterMinorUnits directly — no SUM() aggregation needed.
 *
 * ## counterpartyAccountId
 *   The other account involved in the transfer leg.  Stored on each entry so
 *   that statement consumers can show "you sent $50 to account X" without a
 *   secondary query against the transfers table.
 *
 * Plain PHP — zero framework dependencies.
 */
final class LedgerEntry
{
    private function __construct(
        private readonly LedgerEntryId      $id,
        private readonly AccountId          $accountId,
        private readonly AccountId          $counterpartyAccountId,
        private readonly string             $transferId,
        private readonly EntryType          $entryType,
        private readonly string             $transferType,           // 'transfer' | 'reversal' | 'bootstrap'
        private readonly int                $amountMinorUnits,
        private readonly string             $currency,
        private readonly int                $balanceAfterMinorUnits,
        private readonly \DateTimeImmutable $occurredAt,
        private readonly \DateTimeImmutable $createdAt,
    ) {}


    /**
     * Record a DEBIT leg — funds leaving $accountId toward $counterpartyAccountId.
     *
     * @throws \InvalidArgumentException on invalid amounts or currency
     */
    public static function recordDebit(
        AccountId          $accountId,
        AccountId          $counterpartyAccountId,
        string             $transferId,
        string             $transferType,
        int                $amountMinorUnits,
        string             $currency,
        int                $balanceAfterMinorUnits,
        \DateTimeImmutable $occurredAt,
    ): self {
        self::assertAccountsDiffer($accountId, $counterpartyAccountId);
        self::assertPositiveAmount($amountMinorUnits);
        self::assertNonNegativeBalance($balanceAfterMinorUnits);
        self::assertValidCurrency($currency);
        self::assertValidTransferType($transferType);

        return new self(
            id:                     LedgerEntryId::generate(),
            accountId:              $accountId,
            counterpartyAccountId:  $counterpartyAccountId,
            transferId:             $transferId,
            entryType:              EntryType::DEBIT,
            transferType:           $transferType,
            amountMinorUnits:       $amountMinorUnits,
            currency:               $currency,
            balanceAfterMinorUnits: $balanceAfterMinorUnits,
            occurredAt:             $occurredAt,
            createdAt:              new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * Record a CREDIT leg — funds arriving at $accountId from $counterpartyAccountId.
     *
     * @throws \InvalidArgumentException on invalid amounts or currency
     */
    public static function recordCredit(
        AccountId          $accountId,
        AccountId          $counterpartyAccountId,
        string             $transferId,
        string             $transferType,
        int                $amountMinorUnits,
        string             $currency,
        int                $balanceAfterMinorUnits,
        \DateTimeImmutable $occurredAt,
    ): self {
        self::assertAccountsDiffer($accountId, $counterpartyAccountId);
        self::assertPositiveAmount($amountMinorUnits);
        self::assertNonNegativeBalance($balanceAfterMinorUnits);
        self::assertValidCurrency($currency);
        self::assertValidTransferType($transferType);

        return new self(
            id:                     LedgerEntryId::generate(),
            accountId:              $accountId,
            counterpartyAccountId:  $counterpartyAccountId,
            transferId:             $transferId,
            entryType:              EntryType::CREDIT,
            transferType:           $transferType,
            amountMinorUnits:       $amountMinorUnits,
            currency:               $currency,
            balanceAfterMinorUnits: $balanceAfterMinorUnits,
            occurredAt:             $occurredAt,
            createdAt:              new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * Reconstitute a LedgerEntry from a persistence row.
     * Used exclusively by DbalLedgerRepository::hydrate().
     */
    public static function reconstitute(
        LedgerEntryId      $id,
        AccountId          $accountId,
        AccountId          $counterpartyAccountId,
        string             $transferId,
        EntryType          $entryType,
        string             $transferType,
        int                $amountMinorUnits,
        string             $currency,
        int                $balanceAfterMinorUnits,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id:                     $id,
            accountId:              $accountId,
            counterpartyAccountId:  $counterpartyAccountId,
            transferId:             $transferId,
            entryType:              $entryType,
            transferType:           $transferType,
            amountMinorUnits:       $amountMinorUnits,
            currency:               $currency,
            balanceAfterMinorUnits: $balanceAfterMinorUnits,
            occurredAt:             $occurredAt,
            createdAt:              $createdAt,
        );
    }


    public function getId(): LedgerEntryId
    {
        return $this->id;
    }

    public function getAccountId(): AccountId
    {
        return $this->accountId;
    }

    public function getCounterpartyAccountId(): AccountId
    {
        return $this->counterpartyAccountId;
    }

    public function getTransferId(): string
    {
        return $this->transferId;
    }

    public function getEntryType(): EntryType
    {
        return $this->entryType;
    }

    public function getTransferType(): string
    {
        return $this->transferType;
    }

    public function getAmountMinorUnits(): int
    {
        return $this->amountMinorUnits;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalanceAfterMinorUnits(): int
    {
        return $this->balanceAfterMinorUnits;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }


    private static function assertAccountsDiffer(AccountId $a, AccountId $b): void
    {
        if ($a->equals($b)) {
            throw new \InvalidArgumentException(
                'LedgerEntry: accountId and counterpartyAccountId must be different.',
            );
        }
    }

    private static function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    'LedgerEntry: amountMinorUnits must be > 0, got %d.',
                    $amount,
                ),
            );
        }
    }

    private static function assertNonNegativeBalance(int $balance): void
    {
        if ($balance < 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    'LedgerEntry: balanceAfterMinorUnits must be >= 0, got %d.',
                    $balance,
                ),
            );
        }
    }

    private static function assertValidCurrency(string $currency): void
    {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'LedgerEntry: currency must be 3-char uppercase ISO 4217, got "%s".',
                    $currency,
                ),
            );
        }
    }

    private static function assertValidTransferType(string $type): void
    {
        if (!in_array($type, ['transfer', 'reversal', 'bootstrap'], strict: true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'LedgerEntry: transferType must be "transfer", "reversal", or "bootstrap", got "%s".',
                    $type,
                ),
            );
        }
    }
}
