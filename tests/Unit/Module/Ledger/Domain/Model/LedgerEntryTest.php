<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Ledger\Domain\Model;

use App\Module\Ledger\Domain\Model\LedgerEntry;
use App\Module\Ledger\Domain\ValueObject\AccountId;
use App\Module\Ledger\Domain\ValueObject\EntryType;
use App\Module\Ledger\Domain\ValueObject\LedgerEntryId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LedgerEntry domain model.
 *
 * Verifies:
 *   — recordDebit() produces a DEBIT entry with all fields populated
 *   — recordCredit() produces a CREDIT entry with all fields populated
 *   — reconstitute() restores all persisted fields exactly
 *   — Guard: same accountId and counterpartyAccountId → InvalidArgumentException
 *   — Guard: amountMinorUnits = 0  → InvalidArgumentException
 *   — Guard: amountMinorUnits < 0  → InvalidArgumentException
 *   — Guard: balanceAfterMinorUnits < 0 → InvalidArgumentException
 *   — Guard: currency < 3 chars, lowercase, digits → InvalidArgumentException
 *   — Guard: unknown transferType → InvalidArgumentException
 *   — Zero balanceAfterMinorUnits is valid (full drain)
 *   — 'reversal' transferType is accepted
 */
final class LedgerEntryTest extends TestCase
{
    private AccountId $accountA;
    private AccountId $accountB;

    private const TRANSFER_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    protected function setUp(): void
    {
        $this->accountA = AccountId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $this->accountB = AccountId::fromString('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    }

    // ── recordDebit ────────────────────────────────────────────────────────────

    public function testRecordDebitProducesDebitEntry(): void
    {
        $now   = new \DateTimeImmutable('2026-01-15T10:00:00', new \DateTimeZone('UTC'));
        $entry = LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       1_000,
            currency:               'USD',
            balanceAfterMinorUnits: 9_000,
            occurredAt:             $now,
        );

        self::assertSame(EntryType::DEBIT,   $entry->getEntryType());
        self::assertTrue($entry->getAccountId()->equals($this->accountA));
        self::assertTrue($entry->getCounterpartyAccountId()->equals($this->accountB));
        self::assertSame(self::TRANSFER_ID,  $entry->getTransferId());
        self::assertSame('transfer',         $entry->getTransferType());
        self::assertSame(1_000,              $entry->getAmountMinorUnits());
        self::assertSame('USD',              $entry->getCurrency());
        self::assertSame(9_000,              $entry->getBalanceAfterMinorUnits());
        self::assertEquals($now,             $entry->getOccurredAt());
        // createdAt is set to "now" internally — just check it's a DateTimeImmutable
        self::assertInstanceOf(\DateTimeImmutable::class, $entry->getCreatedAt());
        // id is a fresh UUIDv7
        self::assertInstanceOf(LedgerEntryId::class, $entry->getId());
    }

    // ── recordCredit ───────────────────────────────────────────────────────────

    public function testRecordCreditProducesCreditEntry(): void
    {
        $now   = new \DateTimeImmutable('2026-01-15T10:00:00', new \DateTimeZone('UTC'));
        $entry = LedgerEntry::recordCredit(
            accountId:              $this->accountB,
            counterpartyAccountId:  $this->accountA,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       1_000,
            currency:               'USD',
            balanceAfterMinorUnits: 6_000,
            occurredAt:             $now,
        );

        self::assertSame(EntryType::CREDIT, $entry->getEntryType());
        self::assertTrue($entry->getAccountId()->equals($this->accountB));
        self::assertTrue($entry->getCounterpartyAccountId()->equals($this->accountA));
        self::assertSame(1_000, $entry->getAmountMinorUnits());
        self::assertSame(6_000, $entry->getBalanceAfterMinorUnits());
    }

    // ── reconstitute ──────────────────────────────────────────────────────────

    public function testReconstituteRestoresAllFields(): void
    {
        $id         = LedgerEntryId::generate();
        $occurredAt = new \DateTimeImmutable('2026-01-15T10:00:00.000000', new \DateTimeZone('UTC'));
        $createdAt  = new \DateTimeImmutable('2026-01-15T10:00:00.100000', new \DateTimeZone('UTC'));

        $entry = LedgerEntry::reconstitute(
            id:                     $id,
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            entryType:              EntryType::CREDIT,
            transferType:           'reversal',
            amountMinorUnits:       2_500,
            currency:               'GBP',
            balanceAfterMinorUnits: 12_500,
            occurredAt:             $occurredAt,
            createdAt:              $createdAt,
        );

        self::assertTrue($entry->getId()->equals($id));
        self::assertTrue($entry->getAccountId()->equals($this->accountA));
        self::assertTrue($entry->getCounterpartyAccountId()->equals($this->accountB));
        self::assertSame(self::TRANSFER_ID,     $entry->getTransferId());
        self::assertSame(EntryType::CREDIT,     $entry->getEntryType());
        self::assertSame('reversal',            $entry->getTransferType());
        self::assertSame(2_500,                 $entry->getAmountMinorUnits());
        self::assertSame('GBP',                 $entry->getCurrency());
        self::assertSame(12_500,                $entry->getBalanceAfterMinorUnits());
        self::assertEquals($occurredAt,         $entry->getOccurredAt());
        self::assertEquals($createdAt,          $entry->getCreatedAt());
    }

    // ── Guard: same accounts ───────────────────────────────────────────────────

    public function testSameAccountIdAndCounterpartyThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/accountId and counterpartyAccountId must be different/');

        LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountA,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       1_000,
            currency:               'USD',
            balanceAfterMinorUnits: 0,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function testSameAccountCreditAlsoThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LedgerEntry::recordCredit(
            accountId:              $this->accountB,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       500,
            currency:               'USD',
            balanceAfterMinorUnits: 0,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // ── Guard: amount ──────────────────────────────────────────────────────────

    public function testZeroAmountThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/amountMinorUnits must be > 0/');

        LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       0,
            currency:               'USD',
            balanceAfterMinorUnits: 0,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function testNegativeAmountThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       -1,
            currency:               'USD',
            balanceAfterMinorUnits: 0,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // ── Guard: balanceAfter ────────────────────────────────────────────────────

    public function testNegativeBalanceAfterThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/balanceAfterMinorUnits must be >= 0/');

        LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       1_000,
            currency:               'USD',
            balanceAfterMinorUnits: -1,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function testZeroBalanceAfterIsValid(): void
    {
        // An account fully drained by a debit should produce balanceAfter = 0
        $entry = LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       10_000,
            currency:               'USD',
            balanceAfterMinorUnits: 0,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        self::assertSame(0, $entry->getBalanceAfterMinorUnits());
    }

    // ── Guard: currency ────────────────────────────────────────────────────────

    public function testLowercaseCurrencyThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/currency must be 3-char uppercase ISO 4217/');

        LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       1_000,
            currency:               'usd',
            balanceAfterMinorUnits: 0,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function testTwoCharCurrencyThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LedgerEntry::recordCredit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       500,
            currency:               'US',
            balanceAfterMinorUnits: 500,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function testCurrencyWithDigitsThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'transfer',
            amountMinorUnits:       500,
            currency:               'U5D',
            balanceAfterMinorUnits: 0,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // ── Guard: transferType ────────────────────────────────────────────────────

    public function testUnknownTransferTypeThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/transferType must be "transfer", "reversal", or "bootstrap"/');

        LedgerEntry::recordDebit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'refund',
            amountMinorUnits:       1_000,
            currency:               'USD',
            balanceAfterMinorUnits: 0,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function testReversalTransferTypeIsAccepted(): void
    {
        $entry = LedgerEntry::recordCredit(
            accountId:              $this->accountA,
            counterpartyAccountId:  $this->accountB,
            transferId:             self::TRANSFER_ID,
            transferType:           'reversal',
            amountMinorUnits:       500,
            currency:               'EUR',
            balanceAfterMinorUnits: 1_000,
            occurredAt:             new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        self::assertSame('reversal', $entry->getTransferType());
        self::assertSame('EUR',      $entry->getCurrency());
    }
}
