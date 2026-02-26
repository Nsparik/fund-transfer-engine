<?php

declare(strict_types=1);

namespace App\Shared\Domain\Outbox;

/**
 * Immutable value container representing one row in the outbox_events table.
 *
 * Created by OutboxEventSerializer::serialize() — domain event classes remain
 * free of serialization concerns (Single Responsibility Principle).
 *
 * Transfer events (TransferInitiated, TransferCompleted, TransferFailed,
 * TransferReversed) and Account events (AccountDebited, AccountCredited) are
 * persisted in the Outbox for durable delivery to external consumers.
 */
final class OutboxEvent
{
    public function __construct(
        public readonly OutboxEventId      $id,
        public readonly string             $aggregateType,
        public readonly string             $aggregateId,
        public readonly string             $eventType,
        public readonly array              $payload,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $publishedAt   = null,
        public readonly int                $attemptCount   = 0,
        public readonly ?string            $lastError      = null,
    ) {}
}
