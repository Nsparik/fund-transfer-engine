<?php

declare(strict_types=1);

namespace App\Shared\Domain\Outbox;

/**
 * Domain port — converts domain event objects to/from OutboxEvent payloads.
 *
 * Lives in the Domain layer so Application handlers can depend on this
 * abstraction rather than directly on the Infrastructure serializer class.
 * This preserves the Dependency Inversion Principle: the Application layer
 * depends on the domain port; the Infrastructure layer provides the adapter.
 *
 * Implementations are transfer-context-specific: they know the concrete shape
 * of each event's payload but are kept out of the Domain layer to avoid
 * coupling domain events to serialization concerns.
 */
interface OutboxEventSerializerInterface
{
    /**
     * Convert a domain event into a persistable OutboxEvent value object.
     *
     * @param object $event         The domain event (e.g. TransferInitiated).
     * @param string $aggregateType Human-readable aggregate name (e.g. "Transfer").
     * @param string $aggregateId   The aggregate's UUID string.
     *
     * @throws \InvalidArgumentException when the event type is not supported.
     */
    public function serialize(object $event, string $aggregateType, string $aggregateId): OutboxEvent;

    /**
     * Reconstruct a domain event object from a stored OutboxEvent row.
     *
     * @throws \InvalidArgumentException when the stored event_type is unknown.
     */
    public function deserialize(OutboxEvent $outboxEvent): object;
}
