<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Transfer\Domain\Model;

use App\Module\Transfer\Domain\Event\TransferInitiated;
use App\Module\Transfer\Domain\Event\TransferCompleted;
use App\Module\Transfer\Domain\Event\TransferFailed;
use App\Module\Transfer\Domain\Event\TransferReversed;
use App\Module\Transfer\Domain\Exception\InvalidTransferAmountException;
use App\Module\Transfer\Domain\Exception\InvalidTransferStateException;
use App\Module\Transfer\Domain\Exception\SameAccountTransferException;
use App\Module\Transfer\Domain\Model\Transfer;
use App\Module\Transfer\Domain\Model\TransferStatus;
use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Transfer aggregate root.
 *
 * Covers:
 *  - Factory invariants (initiate)
 *  - Domain event emission + release
 *  - Full state-machine happy paths
 *  - All illegal state-machine transitions
 *  - Reconstitution from persistence
 *  - Timestamp behaviour
 */
final class TransferTest extends TestCase
{
    private TransferId        $id;
    private AccountId         $source;
    private AccountId         $destination;
    private Money             $amount;
    private TransferReference $reference;

    protected function setUp(): void
    {
        $this->id          = TransferId::generate();
        $this->source      = AccountId::fromString('11111111-1111-4111-a111-111111111111');
        $this->destination = AccountId::fromString('22222222-2222-4222-a222-222222222222');
        $this->amount      = new Money(1000, 'USD');
        // Fixed valid reference used by all reconstitute() calls in these tests
        $this->reference   = TransferReference::fromString('TXN-20250101-AABBCCDDEEFF');
    }

    // ── initiate(): invariants ───────────────────────────────────────────

    public function testInitiateCreatesTransferWithPendingStatus(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);

        self::assertSame(TransferStatus::PENDING, $transfer->getStatus());
    }

    public function testInitiateStoresAllProvidedValues(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);

        self::assertTrue($transfer->getId()->equals($this->id));
        self::assertTrue($transfer->getSourceAccountId()->equals($this->source));
        self::assertTrue($transfer->getDestinationAccountId()->equals($this->destination));
        self::assertTrue($transfer->getAmount()->equals($this->amount));
        self::assertNotEmpty($transfer->getReference()->toString());
        self::assertNull($transfer->getDescription());
        self::assertSame(0, $transfer->getVersion());
    }

    public function testInitiateWithSameSourceAndDestinationThrowsSameAccountTransferException(): void
    {
        $this->expectException(SameAccountTransferException::class);
        $this->expectExceptionMessage('different');

        Transfer::initiate($this->id, $this->source, $this->source, $this->amount);
    }

    public function testInitiateWithZeroAmountThrowsInvalidTransferAmountException(): void
    {
        $this->expectException(InvalidTransferAmountException::class);
        $this->expectExceptionMessage('greater than zero');

        Transfer::initiate($this->id, $this->source, $this->destination, new Money(0, 'USD'));
    }

    // ── Domain events ─────────────────────────────────────────────────────

    public function testInitiateRaisesExactlyOneTransferInitiatedEvent(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);
        $events   = $transfer->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(TransferInitiated::class, $events[0]);
    }

    public function testTransferInitiatedEventCarriesCorrectPayload(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);

        /** @var TransferInitiated $event */
        $event = $transfer->releaseEvents()[0];

        self::assertTrue($event->transferId->equals($this->id));
        self::assertTrue($event->reference->equals($transfer->getReference()));
        self::assertTrue($event->sourceAccountId->equals($this->source));
        self::assertTrue($event->destinationAccountId->equals($this->destination));
        self::assertTrue($event->amount->equals($this->amount));
    }

    public function testReleaseEventsClearsThePendingEventQueue(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);
        $transfer->releaseEvents(); // first call drains the queue

        self::assertEmpty($transfer->releaseEvents()); // second call must return []
    }

    // ── State machine: valid transitions ─────────────────────────────────

    public function testPendingToProcessing(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();

        self::assertSame(TransferStatus::PROCESSING, $transfer->getStatus());
    }

    public function testProcessingToCompleted(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();

        self::assertSame(TransferStatus::COMPLETED, $transfer->getStatus());
    }

    public function testProcessingToFailed(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->fail();

        self::assertSame(TransferStatus::FAILED, $transfer->getStatus());
    }

    public function testCompletedToReversed(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();
        $transfer->reverse();

        self::assertSame(TransferStatus::REVERSED, $transfer->getStatus());
    }

    // ── State machine: illegal transitions ───────────────────────────────

    public function testPendingToCompletedIsIllegal(): void
    {
        $this->expectException(InvalidTransferStateException::class);

        $this->pendingTransfer()->complete();
    }

    public function testPendingToFailedIsIllegal(): void
    {
        $this->expectException(InvalidTransferStateException::class);

        $this->pendingTransfer()->fail();
    }

    public function testPendingToReversedIsIllegal(): void
    {
        $this->expectException(InvalidTransferStateException::class);

        $this->pendingTransfer()->reverse();
    }

    public function testCompletedToProcessingIsIllegal(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();

        $this->expectException(InvalidTransferStateException::class);

        $transfer->markAsProcessing();
    }

    public function testCompletedToCompletedIsIllegal(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();

        $this->expectException(InvalidTransferStateException::class);

        $transfer->complete();
    }

    public function testFailedIsTerminal(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->fail();

        $this->expectException(InvalidTransferStateException::class);

        $transfer->markAsProcessing();
    }

    public function testReversedIsTerminal(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();
        $transfer->reverse();

        $this->expectException(InvalidTransferStateException::class);

        $transfer->complete();
    }

    // ── Timestamps ───────────────────────────────────────────────────────

    public function testCreatedAtAndUpdatedAtAreSetOnInitiate(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);

        self::assertInstanceOf(\DateTimeImmutable::class, $transfer->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $transfer->getUpdatedAt());
        // On a brand-new aggregate, both timestamps originate from the same clock tick
        self::assertEquals($transfer->getCreatedAt(), $transfer->getUpdatedAt());
    }

    public function testTransitionAdvancesUpdatedAt(): void
    {
        // Use a known past date so any real clock reading will be strictly later
        $pastDate = new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC'));
        $transfer = Transfer::reconstitute(
            id:                   $this->id,
            reference:            $this->reference,
            sourceAccountId:      $this->source,
            destinationAccountId: $this->destination,
            amount:               $this->amount,
            status:               TransferStatus::PENDING,
            createdAt:            $pastDate,
            updatedAt:            $pastDate,
        );

        $transfer->markAsProcessing();

        self::assertGreaterThan($pastDate, $transfer->getUpdatedAt());
        // createdAt must remain unchanged
        self::assertEquals($pastDate, $transfer->getCreatedAt());
    }

    // ── reconstitute() ───────────────────────────────────────────────────

    public function testReconstituteDoesNotRaiseDomainEvents(): void
    {
        $transfer = Transfer::reconstitute(
            id:                   $this->id,
            reference:            $this->reference,
            sourceAccountId:      $this->source,
            destinationAccountId: $this->destination,
            amount:               $this->amount,
            status:               TransferStatus::PROCESSING,
            createdAt:            new \DateTimeImmutable('2025-01-01 00:00:00'),
            updatedAt:            new \DateTimeImmutable('2025-01-01 00:01:00'),
        );

        self::assertEmpty($transfer->releaseEvents());
    }

    public function testReconstituteRestoresProvidedStatus(): void
    {
        foreach (TransferStatus::cases() as $status) {
            $transfer = Transfer::reconstitute(
                id:                   $this->id,
                reference:            $this->reference,
                sourceAccountId:      $this->source,
                destinationAccountId: $this->destination,
                amount:               $this->amount,
                status:               $status,
                createdAt:            new \DateTimeImmutable(),
                updatedAt:            new \DateTimeImmutable(),
            );

            self::assertSame($status, $transfer->getStatus(), "Status {$status->value} not restored");
        }
    }

    public function testReconstituteRestoresProvidedTimestamps(): void
    {
        $created = new \DateTimeImmutable('2024-06-01 12:00:00');
        $updated = new \DateTimeImmutable('2024-06-02 08:30:00');

        $transfer = Transfer::reconstitute(
            id:                   $this->id,
            reference:            $this->reference,
            sourceAccountId:      $this->source,
            destinationAccountId: $this->destination,
            amount:               $this->amount,
            status:               TransferStatus::COMPLETED,
            createdAt:            $created,
            updatedAt:            $updated,
        );

        self::assertEquals($created, $transfer->getCreatedAt());
        self::assertEquals($updated, $transfer->getUpdatedAt());
    }

    // ── TransferStatus helpers ────────────────────────────────────────────

    public function testTerminalStatusValues(): void
    {
        self::assertTrue(TransferStatus::COMPLETED->isTerminal());
        self::assertTrue(TransferStatus::FAILED->isTerminal());
        self::assertTrue(TransferStatus::REVERSED->isTerminal());
        self::assertFalse(TransferStatus::PENDING->isTerminal());
        self::assertFalse(TransferStatus::PROCESSING->isTerminal());
    }
    // ── Reference ───────────────────────────────────────────────────────────

    public function testInitiateGeneratesHumanReadableReference(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);

        self::assertMatchesRegularExpression(
            '/^TXN-\d{8}-[0-9A-F]{12}$/',
            $transfer->getReference()->toString(),
        );
    }

    public function testDescriptionIsStoredOnInitiate(): void
    {
        $transfer = Transfer::initiate(
            $this->id, $this->source, $this->destination, $this->amount, 'Rent payment Feb 2026'
        );

        self::assertSame('Rent payment Feb 2026', $transfer->getDescription());
    }

    public function testDescriptionDefaultsToNull(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);

        self::assertNull($transfer->getDescription());
    }

    // ── Failure tracking ─────────────────────────────────────────────────

    public function testFailSetsFailureDetailsAndTimestamp(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->fail('INSUFFICIENT_FUNDS', 'Balance too low');

        self::assertSame('INSUFFICIENT_FUNDS', $transfer->getFailureCode());
        self::assertSame('Balance too low', $transfer->getFailureReason());
        self::assertInstanceOf(\DateTimeImmutable::class, $transfer->getFailedAt());
        self::assertNull($transfer->getCompletedAt());
    }

    public function testFailDefaultsToUnknownCode(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->fail();

        self::assertSame('UNKNOWN', $transfer->getFailureCode());
        self::assertSame('', $transfer->getFailureReason());
    }

    public function testCompleteRecordsCompletedAtTimestamp(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();

        self::assertInstanceOf(\DateTimeImmutable::class, $transfer->getCompletedAt());
        self::assertNull($transfer->getFailedAt());
        self::assertNull($transfer->getFailureCode());
    }

    // ── Version ─────────────────────────────────────────────────────────────

    public function testVersionStartsAtZeroOnInitiate(): void
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);

        self::assertSame(0, $transfer->getVersion());
    }

    public function testEachTransitionIncrementsVersionByOne(): void
    {
        $transfer = $this->pendingTransfer(); // version 0

        $transfer->markAsProcessing();        // version 1
        self::assertSame(1, $transfer->getVersion());

        $transfer->complete();                // version 2
        self::assertSame(2, $transfer->getVersion());
    }

    public function testReconstitutedVersionIsRestored(): void
    {
        $transfer = Transfer::reconstitute(
            id:                   $this->id,
            reference:            $this->reference,
            sourceAccountId:      $this->source,
            destinationAccountId: $this->destination,
            amount:               $this->amount,
            status:               TransferStatus::COMPLETED,
            createdAt:            new \DateTimeImmutable(),
            updatedAt:            new \DateTimeImmutable(),
            version:              5,
        );

        self::assertSame(5, $transfer->getVersion());
    }

    // ── TransferCompleted event ───────────────────────────────────────────────

    public function testCompleteRaisesExactlyOneTransferCompletedEvent(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();
        $events = $transfer->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(TransferCompleted::class, $events[0]);
    }

    public function testTransferCompletedEventCarriesCorrectPayload(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();

        /** @var TransferCompleted $event */
        $event = $transfer->releaseEvents()[0];

        self::assertTrue($event->transferId->equals($this->id));
        self::assertTrue($event->reference->equals($transfer->getReference()));
        self::assertTrue($event->sourceAccountId->equals($this->source));
        self::assertTrue($event->destinationAccountId->equals($this->destination));
        self::assertTrue($event->amount->equals($this->amount));
        self::assertNotNull($event->occurredAt);
        self::assertEqualsWithDelta(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp(),
            $event->occurredAt->getTimestamp(),
            5,
        );
    }

    // ── TransferFailed event ──────────────────────────────────────────────────

    public function testFailRaisesExactlyOneTransferFailedEvent(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->fail('INSUFFICIENT_FUNDS', 'Not enough balance');
        $events = $transfer->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(TransferFailed::class, $events[0]);
    }

    public function testTransferFailedEventCarriesCorrectPayload(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->fail('ACCOUNT_FROZEN', 'Source account is frozen');

        /** @var TransferFailed $event */
        $event = $transfer->releaseEvents()[0];

        self::assertTrue($event->transferId->equals($this->id));
        self::assertTrue($event->reference->equals($transfer->getReference()));
        self::assertTrue($event->sourceAccountId->equals($this->source));
        self::assertTrue($event->destinationAccountId->equals($this->destination));
        self::assertTrue($event->amount->equals($this->amount));
        self::assertSame('ACCOUNT_FROZEN', $event->failureCode);
        self::assertSame('Source account is frozen', $event->failureReason);
        self::assertNotNull($event->occurredAt);
    }

    public function testFailWithDefaultsEmitsEventWithUnknownCode(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->fail(); // uses defaults: UNKNOWN / ''

        /** @var TransferFailed $event */
        $event = $transfer->releaseEvents()[0];

        self::assertSame('UNKNOWN', $event->failureCode);
        self::assertSame('', $event->failureReason);
    }

    // ── Reversal ─────────────────────────────────────────────────────────────

    public function testReverseRaisesTransferReversedEvent(): void
    {
        $transfer = $this->pendingTransfer();
        $transfer->markAsProcessing();
        $transfer->complete();
        $transfer->releaseEvents(); // drain any prior events

        $transfer->reverse();
        $events = $transfer->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(TransferReversed::class, $events[0]);
        self::assertTrue($events[0]->transferId->equals($this->id));
        self::assertTrue($events[0]->reference->equals($transfer->getReference()));
        self::assertTrue($events[0]->amount->equals($this->amount));
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function pendingTransfer(): Transfer
    {
        $transfer = Transfer::initiate($this->id, $this->source, $this->destination, $this->amount);
        $transfer->releaseEvents(); // discard init events so they don't pollute other assertions

        return $transfer;
    }
}
