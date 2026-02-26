<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * An opaque domain event paired with its aggregate's ID for outbox routing.
 *
 * Produced by AccountTransferPort::executeDoubleEntry() and carried inside
 * DoubleEntryResult::$events.  The Transfer module iterates these in the
 * handler and writes each event to the outbox using the correct aggregateId —
 * without importing any Account domain types.
 *
 * ## Why this matters for fintech auditing
 *   Each outbox row's aggregate_id must match the account the event belongs to.
 *   Without TaggedEvent, both AccountDebited (source account) and AccountCredited
 *   (destination account) would be stored with the source account's ID — making
 *   per-account event replay and ACL/microservice extraction impossible.
 *
 * Plain PHP value object — zero framework dependencies.
 */
final readonly class TaggedEvent
{
    /**
     * @param object $event       The domain event to be serialized and stored in the outbox.
     * @param string $aggregateId The UUID of the aggregate that raised this event.
     *                            For AccountDebited  → ID of the debited  account.
     *                            For AccountCredited → ID of the credited account.
     */
    public function __construct(
        public readonly object $event,
        public readonly string $aggregateId,
    ) {}
}
