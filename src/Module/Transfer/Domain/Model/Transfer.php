<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Model;

use App\Module\Transfer\Domain\Event\TransferCompleted;
use App\Module\Transfer\Domain\Event\TransferFailed;
use App\Module\Transfer\Domain\Event\TransferInitiated;
use App\Module\Transfer\Domain\Event\TransferReversed;
use App\Module\Transfer\Domain\Exception\InvalidTransferAmountException;
use App\Module\Transfer\Domain\Exception\InvalidTransferStateException;
use App\Module\Transfer\Domain\Exception\SameAccountTransferException;
use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;

/**
 * Transfer — the Aggregate Root of the Transfer bounded context.
 *
 * ## Invariants enforced by this class
 *   1. Source and destination accounts must differ.
 *   2. Transfer amount must be > 0.
 *   3. State transitions must follow the defined state machine.
 *

 * ## Domain events
 *   Events are collected in $domainEvents and released via releaseEvents().
 *   The Infrastructure layer dispatches them AFTER the aggregate is persisted.
 *
 * ## Reconstitution
 *   Use Transfer::reconstitute() to rebuild from persistence.
 *   That path does NOT raise domain events.
 */
final class Transfer
{
    private TransferStatus $status;
    private \DateTimeImmutable $updatedAt;
    private ?string $failureCode    = null;
    private ?string $failureReason  = null;
    private ?\DateTimeImmutable $completedAt  = null;
    private ?\DateTimeImmutable $failedAt     = null;
    private ?\DateTimeImmutable $reversedAt   = null;
    private int $version = 0;

    /** @var list<object> */
    private array $domainEvents = [];

    private function __construct(
        private readonly TransferId        $id,
        private readonly TransferReference $reference,
        private readonly AccountId         $sourceAccountId,
        private readonly AccountId         $destinationAccountId,
        private readonly Money             $amount,
        private readonly ?string           $description,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?string           $idempotencyKey = null,
    ) {
        $this->status    = TransferStatus::PENDING;
        $this->updatedAt = $createdAt;
    }


    /**
     * Create and initiate a brand-new Transfer.
     *
     * Generates a human-readable reference from the transfer ID and timestamp.
     * Raises TransferInitiated domain event.
     *
     * @throws SameAccountTransferException   when source == destination
     * @throws InvalidTransferAmountException when amount is zero
     */
    public static function initiate(
        TransferId $id,
        AccountId  $sourceAccountId,
        AccountId  $destinationAccountId,
        Money      $amount,
        ?string    $description = null,
        ?string    $idempotencyKey = null,
    ): self {
        if ($sourceAccountId->equals($destinationAccountId)) {
            throw new SameAccountTransferException(
                'Source and destination accounts must be different.'
            );
        }

        if ($amount->isZero()) {
            throw new InvalidTransferAmountException(
                'Transfer amount must be greater than zero.'
            );
        }

        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $reference = TransferReference::generate($id, $now);

        $transfer = new self(
            $id,
            $reference,
            $sourceAccountId,
            $destinationAccountId,
            $amount,
            $description,
            $now,
            $idempotencyKey,
        );

        $transfer->record(new TransferInitiated(
            transferId:           $id,
            reference:            $reference,
            sourceAccountId:      $sourceAccountId,
            destinationAccountId: $destinationAccountId,
            amount:               $amount,
            occurredAt:           $now,
        ));

        return $transfer;
    }

    /**
     * Rebuild a Transfer from a persistence row.  No events are raised.
     *
     * Params after $updatedAt are optional (nullable/defaulted) to support
     * backward-compatible schema migrations under rolling deployments.
     */
    public static function reconstitute(
        TransferId          $id,
        TransferReference   $reference,
        AccountId           $sourceAccountId,
        AccountId           $destinationAccountId,
        Money               $amount,
        TransferStatus      $status,
        \DateTimeImmutable  $createdAt,
        \DateTimeImmutable  $updatedAt,
        ?string             $description   = null,
        ?string             $failureCode   = null,
        ?string             $failureReason = null,
        ?\DateTimeImmutable $completedAt   = null,
        ?\DateTimeImmutable $failedAt      = null,
        ?\DateTimeImmutable $reversedAt    = null,
        int                 $version       = 0,
        ?string             $idempotencyKey = null,
    ): self {
        $transfer = new self($id, $reference, $sourceAccountId, $destinationAccountId, $amount, $description, $createdAt, $idempotencyKey);
        $transfer->status        = $status;
        $transfer->updatedAt     = $updatedAt;
        $transfer->failureCode   = $failureCode;
        $transfer->failureReason = $failureReason;
        $transfer->completedAt   = $completedAt;
        $transfer->failedAt      = $failedAt;
        $transfer->reversedAt    = $reversedAt;
        $transfer->version       = $version;

        return $transfer;
    }


    /**
     * Transition in-memory state to PROCESSING before executing the double-entry.
     *
     * ## INVARIANT: PROCESSING is ephemeral — never persisted
     *
     * The transfer is first saved to the DB as COMPLETED (or FAILED) inside the
     * same transaction.  PROCESSING therefore never appears as a stable row in
     * the transfers table.  If you add a transfers->save($transfer) call after
     * markAsProcessing() — e.g. to "track progress" — you will expose PROCESSING
     * rows to callers and break the assumption in the state machine (no state
     * allows transitioning OUT of PROCESSING except COMPLETED/FAILED, and no
     * retry logic handles a stuck PROCESSING row).
     *
     * @throws InvalidTransferStateException
     */
    public function markAsProcessing(): void
    {
        $this->transitionTo(TransferStatus::PROCESSING);
    }

    /**
     * @throws InvalidTransferStateException
     */
    public function complete(): void
    {
        $this->transitionTo(TransferStatus::COMPLETED);
        // Reuse the timestamp already set by transitionTo() so that
        // completedAt == updatedAt == event occurredAt — one clock read.
        $this->completedAt = $this->updatedAt;

        $this->record(new TransferCompleted(
            transferId:           $this->id,
            reference:            $this->reference,
            sourceAccountId:      $this->sourceAccountId,
            destinationAccountId: $this->destinationAccountId,
            amount:               $this->amount,
            occurredAt:           $this->completedAt,
        ));
    }

    /**
     * @param string $failureCode   Machine-readable code, e.g. "INSUFFICIENT_FUNDS"
     * @param string $failureReason Human-readable explanation for support/audit
     *
     * @throws InvalidTransferStateException
     */
    public function fail(string $failureCode = 'UNKNOWN', string $failureReason = ''): void
    {
        $this->transitionTo(TransferStatus::FAILED);
        $this->failureCode   = $failureCode;
        $this->failureReason = $failureReason;
        // Reuse the timestamp already set by transitionTo() — one clock read.
        $this->failedAt      = $this->updatedAt;

        $this->record(new TransferFailed(
            transferId:           $this->id,
            reference:            $this->reference,
            sourceAccountId:      $this->sourceAccountId,
            destinationAccountId: $this->destinationAccountId,
            amount:               $this->amount,
            failureCode:          $failureCode,
            failureReason:        $failureReason,
            occurredAt:           $this->failedAt,
        ));
    }

    /**
     * @throws InvalidTransferStateException
     */
    public function reverse(): void
    {
        $this->transitionTo(TransferStatus::REVERSED);
        // Reuse the timestamp set by transitionTo() — one clock read.
        $this->reversedAt = $this->updatedAt;

        // Include both account IDs so downstream consumers (ledger
        // reconciliation, notification service) can act without a
        // secondary query — critical for future microservice extraction.
        $this->record(new TransferReversed(
            transferId:           $this->id,
            reference:            $this->reference,
            sourceAccountId:      $this->sourceAccountId,
            destinationAccountId: $this->destinationAccountId,
            amount:               $this->amount,
            occurredAt:           $this->updatedAt,
        ));
    }


    /**
     * Return collected domain events WITHOUT clearing the internal list.
     *
     * Used by the Outbox writer to persist events atomically inside the
     * transaction BEFORE the handler calls releaseEvents() to clear them.
     *
     * @return list<object>
     */
    public function peekEvents(): array
    {
        return $this->domainEvents;
    }

    public function releaseEvents(): array
    {
        $events             = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }


    public function getId(): TransferId
    {
        return $this->id;
    }

    public function getReference(): TransferReference
    {
        return $this->reference;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getSourceAccountId(): AccountId
    {
        return $this->sourceAccountId;
    }

    public function getDestinationAccountId(): AccountId
    {
        return $this->destinationAccountId;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    public function getFailureCode(): ?string
    {
        return $this->failureCode;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function getReversedAt(): ?\DateTimeImmutable
    {
        return $this->reversedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }


    private function transitionTo(TransferStatus $next): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new InvalidTransferStateException(sprintf(
                'Cannot transition Transfer "%s" from "%s" to "%s".',
                $this->id->toString(),
                $this->status->value,
                $next->value,
            ));
        }

        $this->status    = $next;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->version++;
    }

    private function record(object $event): void
    {
        $this->domainEvents[] = $event;
    }
}
