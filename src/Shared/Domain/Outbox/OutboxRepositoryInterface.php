<?php

declare(strict_types=1);

namespace App\Shared\Domain\Outbox;

/**
 * Persistence port for the transactional outbox.
 *
 * Implementations must be called from inside an active DB transaction (save)
 * or acquire their own row-level locks via SKIP LOCKED (findUnpublished).
 */
interface OutboxRepositoryInterface
{
    /**
     * Persist a new outbox event row.
     *
     * Must be called inside the same transaction as the business operation
     * (InitiateTransferHandler, ReverseTransferHandler) so the event write
     * is atomic with the aggregate state change.
     */
    public function save(OutboxEvent $event): void;

    /**
     * Return up to $limit unpublished events in creation order.
     *
     * Uses SELECT … FOR UPDATE SKIP LOCKED so two concurrent workers cannot
     * pick the same batch.
     *
     * @return list<OutboxEvent>
     */
    public function findUnpublished(int $limit = 100): array;

    /**
     * Mark an event as successfully published (sets published_at = NOW(6)).
     */
    public function markPublished(OutboxEventId $id): void;

    /**
     * Record a dispatch failure — increment attempt_count and set last_error.
     */
    public function markFailed(OutboxEventId $id, string $error): void;

    /**
     * Count events that have been stuck unpublished for longer than the given
     * threshold. Used by the health endpoint to detect a stalled processor.
     *
     * The $thresholdMinutes value is a compile-time constant injected by the
     * caller — it must NOT be passed as a PDO bind parameter because MySQL
     * rejects named/positional parameters inside INTERVAL expressions.
     */
    public function countStuckEvents(int $thresholdMinutes): int;

    /**
     * Return events that have exhausted all retries and are dead-lettered.
     *
     * Dead-lettered = published_at IS NULL AND attempt_count >= MAX_ATTEMPTS.
     * Optionally filter to a single event by UUID string.
     *
     * Used exclusively by RequeueOutboxDeadLetterCommand — never called from
     * the hot path.
     *
     * @return list<OutboxEvent>
     */
    public function findDeadLettered(int $limit = 1000, ?string $id = null): array;

    /**
     * Reset a dead-lettered event so OutboxProcessor will retry it.
     *
     * Sets attempt_count = 0 and last_error = NULL.  Does NOT change published_at
     * (still NULL — the event will be picked up by the next findUnpublished() poll).
     *
     * Safe to call multiple times (idempotent).
     */
    public function resetForRequeue(OutboxEventId $id): void;

    /**
     * Bulk-reset all dead-lettered events whose attempt_count >= $maxAttempts.
     *
     * Sets attempt_count = 0 and last_error = NULL for every matching row so
     * OutboxProcessor will retry them on its next poll cycle.
     *
     * Returns the number of rows affected (0 if no dead-lettered events found).
     */
    public function resetDeadLetters(int $maxAttempts = 5): int;
}
