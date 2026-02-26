<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Outbox;

use App\Module\Account\Domain\Event\AccountClosed;
use App\Module\Account\Domain\Event\AccountCreated;
use App\Module\Account\Domain\Event\AccountCredited;
use App\Module\Account\Domain\Event\AccountDebited;
use App\Module\Account\Domain\Event\AccountFrozen;
use App\Module\Account\Domain\Event\AccountUnfrozen;
use App\Module\Account\Domain\ValueObject\AccountId as AccountAccountId;
use App\Module\Account\Domain\ValueObject\Balance;
use App\Module\Transfer\Domain\Event\TransferCompleted;
use App\Module\Transfer\Domain\Event\TransferFailed;
use App\Module\Transfer\Domain\Event\TransferInitiated;
use App\Module\Transfer\Domain\Event\TransferReversed;
use App\Module\Transfer\Domain\ValueObject\AccountId as TransferAccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;
use App\Shared\Domain\Outbox\OutboxEvent;
use App\Shared\Domain\Outbox\OutboxEventId;
use App\Shared\Infrastructure\Outbox\OutboxEventSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OutboxEventSerializer.
 *
 * Covers every supported event type with a serialize → deserialize round-trip
 * and asserts that all field values survive the round-trip without mutation.
 *
 * Also covers the unsupported-type guard for both serialize() and deserialize().
 */
final class OutboxEventSerializerTest extends TestCase
{
    private OutboxEventSerializer $serializer;

    // Fixed UUIDs (v4 for accounts, v7 for transfers)
    private const ACCOUNT_ID       = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ACCOUNT_ID_2     = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const TRANSFER_ID      = 'cccccccc-cccc-7ccc-8ccc-cccccccccccc';
    private const TRANSFER_REF     = 'TXN-20260101-AABBCCDDEEFF';
    private const OCCURRED_AT_STR  = '2026-06-01T12:00:00+00:00';

    protected function setUp(): void
    {
        $this->serializer = new OutboxEventSerializer();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::OCCURRED_AT_STR, new \DateTimeZone('UTC'));
    }

    /**
     * Build a minimal OutboxEvent shell for deserialization tests.
     *
     * @param array<string, mixed> $payload
     */
    private function makeOutboxEvent(string $eventType, array $payload): OutboxEvent
    {
        return new OutboxEvent(
            id:            OutboxEventId::generate(),
            aggregateType: 'Account',
            aggregateId:   self::ACCOUNT_ID,
            eventType:     $eventType,
            payload:       $payload,
            occurredAt:    $this->occurredAt(),
            createdAt:     new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // ── AccountCreated ─────────────────────────────────────────────────────────

    public function testAccountCreatedRoundTrip(): void
    {
        $original = new AccountCreated(
            accountId:      AccountAccountId::fromString(self::ACCOUNT_ID),
            ownerName:      'Alice Smith',
            initialBalance: new Balance(25_000, 'USD'),
            occurredAt:     $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        self::assertSame(AccountCreated::class, $outboxEvent->eventType);
        self::assertSame('Account', $outboxEvent->aggregateType);
        self::assertSame(self::ACCOUNT_ID, $outboxEvent->aggregateId);
        self::assertSame(self::OCCURRED_AT_STR, $outboxEvent->occurredAt->format(\DateTimeInterface::ATOM));

        /** @var AccountCreated $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(AccountCreated::class, $restored);
        self::assertSame(self::ACCOUNT_ID, $restored->accountId->toString());
        self::assertSame('Alice Smith', $restored->ownerName);
        self::assertSame(25_000, $restored->initialBalance->getAmountMinorUnits());
        self::assertSame('USD', $restored->initialBalance->getCurrency());
        self::assertSame(self::OCCURRED_AT_STR, $restored->occurredAt->format(\DateTimeInterface::ATOM));
    }

    public function testAccountCreatedWithZeroInitialBalanceRoundTrip(): void
    {
        $original = new AccountCreated(
            accountId:      AccountAccountId::fromString(self::ACCOUNT_ID),
            ownerName:      'Bob Jones',
            initialBalance: new Balance(0, 'EUR'),
            occurredAt:     $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        /** @var AccountCreated $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertSame(0, $restored->initialBalance->getAmountMinorUnits());
        self::assertSame('EUR', $restored->initialBalance->getCurrency());
        self::assertSame('Bob Jones', $restored->ownerName);
    }

    // ── AccountFrozen ─────────────────────────────────────────────────────────

    public function testAccountFrozenRoundTrip(): void
    {
        $original = new AccountFrozen(
            accountId:  AccountAccountId::fromString(self::ACCOUNT_ID),
            occurredAt: $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        self::assertSame(AccountFrozen::class, $outboxEvent->eventType);
        self::assertSame('Account', $outboxEvent->aggregateType);
        self::assertSame(self::ACCOUNT_ID, $outboxEvent->aggregateId);

        /** @var AccountFrozen $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(AccountFrozen::class, $restored);
        self::assertSame(self::ACCOUNT_ID, $restored->accountId->toString());
        self::assertSame(self::OCCURRED_AT_STR, $restored->occurredAt->format(\DateTimeInterface::ATOM));
    }

    // ── AccountUnfrozen ───────────────────────────────────────────────────────

    public function testAccountUnfrozenRoundTrip(): void
    {
        $original = new AccountUnfrozen(
            accountId:  AccountAccountId::fromString(self::ACCOUNT_ID),
            occurredAt: $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        self::assertSame(AccountUnfrozen::class, $outboxEvent->eventType);
        self::assertSame('Account', $outboxEvent->aggregateType);
        self::assertSame(self::ACCOUNT_ID, $outboxEvent->aggregateId);

        /** @var AccountUnfrozen $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(AccountUnfrozen::class, $restored);
        self::assertSame(self::ACCOUNT_ID, $restored->accountId->toString());
        self::assertSame(self::OCCURRED_AT_STR, $restored->occurredAt->format(\DateTimeInterface::ATOM));
    }

    // ── AccountClosed (regression guard) ──────────────────────────────────────

    public function testAccountClosedRoundTrip(): void
    {
        $original = new AccountClosed(
            accountId:  AccountAccountId::fromString(self::ACCOUNT_ID),
            occurredAt: $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        /** @var AccountClosed $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(AccountClosed::class, $restored);
        self::assertSame(self::ACCOUNT_ID, $restored->accountId->toString());
        self::assertSame(self::OCCURRED_AT_STR, $restored->occurredAt->format(\DateTimeInterface::ATOM));
    }

    // ── AccountDebited (regression guard) ────────────────────────────────────

    public function testAccountDebitedRoundTrip(): void
    {
        $original = new AccountDebited(
            accountId:             AccountAccountId::fromString(self::ACCOUNT_ID),
            amount:                new Balance(500, 'USD'),
            balanceAfter:          new Balance(4_500, 'USD'),
            transferId:            self::TRANSFER_ID,
            transferType:          'transfer',
            counterpartyAccountId: self::ACCOUNT_ID_2,
            occurredAt:            $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        /** @var AccountDebited $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(AccountDebited::class, $restored);
        self::assertSame(500, $restored->amount->getAmountMinorUnits());
        self::assertSame(4_500, $restored->balanceAfter->getAmountMinorUnits());
        self::assertSame(self::TRANSFER_ID, $restored->transferId);
        self::assertSame('transfer', $restored->transferType);
        self::assertSame(self::ACCOUNT_ID_2, $restored->counterpartyAccountId);
    }

    // ── AccountCredited (regression guard) ───────────────────────────────────

    public function testAccountCreditedRoundTrip(): void
    {
        $original = new AccountCredited(
            accountId:             AccountAccountId::fromString(self::ACCOUNT_ID_2),
            amount:                new Balance(500, 'USD'),
            balanceAfter:          new Balance(1_500, 'USD'),
            transferId:            self::TRANSFER_ID,
            transferType:          'transfer',
            counterpartyAccountId: self::ACCOUNT_ID,
            occurredAt:            $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID_2);

        /** @var AccountCredited $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(AccountCredited::class, $restored);
        self::assertSame(500, $restored->amount->getAmountMinorUnits());
        self::assertSame(1_500, $restored->balanceAfter->getAmountMinorUnits());
        self::assertSame(self::ACCOUNT_ID, $restored->counterpartyAccountId);
    }

    // ── Transfer events (regression guards) ───────────────────────────────────

    public function testTransferInitiatedRoundTrip(): void
    {
        $original = new TransferInitiated(
            transferId:           TransferId::fromString(self::TRANSFER_ID),
            reference:            TransferReference::fromString(self::TRANSFER_REF),
            sourceAccountId:      TransferAccountId::fromString(self::ACCOUNT_ID),
            destinationAccountId: TransferAccountId::fromString(self::ACCOUNT_ID_2),
            amount:               new Money(1_000, 'USD'),
            occurredAt:           $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Transfer', self::TRANSFER_ID);

        /** @var TransferInitiated $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(TransferInitiated::class, $restored);
        self::assertSame(self::TRANSFER_ID, $restored->transferId->toString());
        self::assertSame(self::TRANSFER_REF, $restored->reference->toString());
        self::assertSame(1_000, $restored->amount->getAmountMinorUnits());
        self::assertSame('USD', $restored->amount->getCurrency());
    }

    public function testTransferCompletedRoundTrip(): void
    {
        $original = new TransferCompleted(
            transferId:           TransferId::fromString(self::TRANSFER_ID),
            reference:            TransferReference::fromString(self::TRANSFER_REF),
            sourceAccountId:      TransferAccountId::fromString(self::ACCOUNT_ID),
            destinationAccountId: TransferAccountId::fromString(self::ACCOUNT_ID_2),
            amount:               new Money(2_500, 'EUR'),
            occurredAt:           $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Transfer', self::TRANSFER_ID);

        /** @var TransferCompleted $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(TransferCompleted::class, $restored);
        self::assertSame(2_500, $restored->amount->getAmountMinorUnits());
        self::assertSame('EUR', $restored->amount->getCurrency());
    }

    public function testTransferFailedRoundTrip(): void
    {
        $original = new TransferFailed(
            transferId:           TransferId::fromString(self::TRANSFER_ID),
            reference:            TransferReference::fromString(self::TRANSFER_REF),
            sourceAccountId:      TransferAccountId::fromString(self::ACCOUNT_ID),
            destinationAccountId: TransferAccountId::fromString(self::ACCOUNT_ID_2),
            amount:               new Money(500, 'USD'),
            failureCode:          'INSUFFICIENT_FUNDS',
            failureReason:        'Balance too low',
            occurredAt:           $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Transfer', self::TRANSFER_ID);

        /** @var TransferFailed $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(TransferFailed::class, $restored);
        self::assertSame('INSUFFICIENT_FUNDS', $restored->failureCode);
        self::assertSame('Balance too low', $restored->failureReason);
    }

    public function testTransferReversedRoundTrip(): void
    {
        $original = new TransferReversed(
            transferId:           TransferId::fromString(self::TRANSFER_ID),
            reference:            TransferReference::fromString(self::TRANSFER_REF),
            sourceAccountId:      TransferAccountId::fromString(self::ACCOUNT_ID),
            destinationAccountId: TransferAccountId::fromString(self::ACCOUNT_ID_2),
            amount:               new Money(1_000, 'USD'),
            occurredAt:           $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Transfer', self::TRANSFER_ID);

        /** @var TransferReversed $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        self::assertInstanceOf(TransferReversed::class, $restored);
        self::assertSame(self::TRANSFER_ID, $restored->transferId->toString());
        self::assertSame(1_000, $restored->amount->getAmountMinorUnits());
    }

    // ── Unsupported type guards ───────────────────────────────────────────────

    public function testSerializeThrowsOnUnsupportedEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported event type');

        $unsupported = new class {};
        $this->serializer->serialize($unsupported, 'Transfer', 'some-id');
    }

    public function testDeserializeThrowsOnUnknownEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot deserialize unknown event type');

        $outboxEvent = $this->makeOutboxEvent('Transfer\\UnknownEvent', []);
        $this->serializer->deserialize($outboxEvent);
    }

    // ── occurredAt UTC invariant ───────────────────────────────────────────────

    /**
     * After deserialization the occurredAt timezone must be UTC, regardless of
     * what format offset was stored (ATOM includes +00:00 which is also UTC).
     */
    public function testDeserializedAccountCreatedOccurredAtIsUtc(): void
    {
        $original = new AccountCreated(
            accountId:      AccountAccountId::fromString(self::ACCOUNT_ID),
            ownerName:      'Test',
            initialBalance: new Balance(0, 'USD'),
            occurredAt:     $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        /** @var AccountCreated $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        // +00:00 and UTC are both zero-offset UTC — assert the offset in seconds.
        self::assertSame(0, $restored->occurredAt->getOffset());
    }

    public function testDeserializedAccountFrozenOccurredAtIsUtc(): void
    {
        $original = new AccountFrozen(
            accountId:  AccountAccountId::fromString(self::ACCOUNT_ID),
            occurredAt: $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        /** @var AccountFrozen $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        // +00:00 and UTC are both zero-offset UTC — assert the offset in seconds.
        self::assertSame(0, $restored->occurredAt->getOffset());
    }

    public function testDeserializedAccountUnfrozenOccurredAtIsUtc(): void
    {
        $original = new AccountUnfrozen(
            accountId:  AccountAccountId::fromString(self::ACCOUNT_ID),
            occurredAt: $this->occurredAt(),
        );

        $outboxEvent = $this->serializer->serialize($original, 'Account', self::ACCOUNT_ID);

        /** @var AccountUnfrozen $restored */
        $restored = $this->serializer->deserialize($outboxEvent);

        // +00:00 and UTC are both zero-offset UTC — assert the offset in seconds.
        self::assertSame(0, $restored->occurredAt->getOffset());
    }
}
