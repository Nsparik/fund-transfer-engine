<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Outbox\OutboxEvent;
use App\Shared\Domain\Outbox\OutboxEventId;
use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed implementation of OutboxRepositoryInterface.
 *
 * ## Concurrency safety
 *   findUnpublished() uses SELECT … FOR UPDATE SKIP LOCKED so two concurrent
 *   OutboxProcessor workers never pick the same batch of events.
 *   Each worker independently acquires its own row-level locks and processes
 *   only the rows it owns.
 *
 * ## Timestamp precision
 *   All DATETIME(6) columns are stored and read with microsecond precision
 *   to preserve the natural sort order of UUID v7 event IDs.
 */
final class DbalOutboxRepository implements OutboxRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly Connection $connection) {}

    public function save(OutboxEvent $event): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO outbox_events
                (id, aggregate_type, aggregate_id, event_type, payload,
                 occurred_at, created_at, published_at, attempt_count, last_error)
            VALUES
                (:id, :aggregate_type, :aggregate_id, :event_type, :payload,
                 :occurred_at, :created_at, :published_at, :attempt_count, :last_error)
            SQL,
            [
                'id'             => $event->id->toString(),
                'aggregate_type' => $event->aggregateType,
                'aggregate_id'   => $event->aggregateId,
                'event_type'     => $event->eventType,
                'payload'        => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'occurred_at'    => $event->occurredAt->format(self::DATE_FORMAT),
                'created_at'     => $event->createdAt->format(self::DATE_FORMAT),
                'published_at'   => $event->publishedAt?->format(self::DATE_FORMAT),
                'attempt_count'  => $event->attemptCount,
                'last_error'     => $event->lastError,
            ],
        );
    }

    /**
     * {@inheritDoc}
     *
     * SELECT … FOR UPDATE SKIP LOCKED: rows locked by another worker's open
     * transaction are silently skipped, preventing double-processing.
     */
    public function findUnpublished(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                SELECT id, aggregate_type, aggregate_id, event_type, payload,
                       occurred_at, created_at, published_at,
                       attempt_count, last_error
                FROM   outbox_events
                WHERE  published_at IS NULL
                ORDER  BY created_at ASC
                LIMIT  %d
                FOR UPDATE SKIP LOCKED
                SQL,
                max(1, $limit),
            ),
        );

        return array_map($this->hydrateRow(...), $rows);
    }

    public function markPublished(OutboxEventId $id): void
    {
        $this->connection->executeStatement(
            'UPDATE outbox_events SET published_at = :now WHERE id = :id',
            [
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                             ->format(self::DATE_FORMAT),
                'id'  => $id->toString(),
            ],
        );
    }

    public function markFailed(OutboxEventId $id, string $error): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE outbox_events
            SET    attempt_count = attempt_count + 1,
                   last_error    = :error
            WHERE  id = :id
            SQL,
            [
                // mb_strcut trims to 65535 bytes (TEXT column max) at a valid
                // UTF-8 character boundary — mb_substr counts characters and
                // can produce a string up to 4× larger than the column allows,
                // causing a hard MySQL error under STRICT_TRANS_TABLES.
                'error' => mb_strcut($error, 0, 65535, 'UTF-8'),
                'id'    => $id->toString(),
            ],
        );
    }
    public function countStuckEvents(int $thresholdMinutes): int
    {
        // MySQL does not accept PDO bind parameters inside INTERVAL expressions.
        // $thresholdMinutes is a caller-supplied constant (never user input), so
        // sprintf with %d (integer cast) is the correct safe approach here.
        return (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM outbox_events
                  WHERE published_at IS NULL
                    AND created_at < NOW() - INTERVAL %d MINUTE',
                max(1, $thresholdMinutes),
            ),
        );
    }

    /** {@inheritDoc} */
    public function findDeadLettered(int $limit = 1000, ?string $id = null): array
    {
        // Dead-lettered = published_at IS NULL AND attempt_count >= 5.
        // The threshold mirrors OutboxProcessor::MAX_ATTEMPTS.
        $sql    = 'SELECT id, aggregate_type, aggregate_id, event_type, payload,
                          occurred_at, created_at, published_at,
                          attempt_count, last_error
                   FROM   outbox_events
                   WHERE  published_at IS NULL
                     AND  attempt_count >= 5';
        $params = [];

        if ($id !== null) {
            $sql      .= ' AND id = ?';
            $params[] = $id;
        }

        $sql .= sprintf(' ORDER BY created_at ASC LIMIT %d', max(1, $limit));

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map($this->hydrateRow(...), $rows);
    }

    /** {@inheritDoc} */
    public function resetForRequeue(OutboxEventId $id): void
    {
        $this->connection->executeStatement(
            'UPDATE outbox_events
              SET    attempt_count = 0,
                     last_error    = NULL
              WHERE  id = :id
                AND  published_at IS NULL',
            ['id' => $id->toString()],
        );
    }

    /** {@inheritDoc} */
    public function resetDeadLetters(int $maxAttempts = 5): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE outbox_events
                SET    attempt_count = 0,
                       last_error    = NULL
              WHERE  published_at IS NULL
                AND  attempt_count >= :max_attempts',
            ['max_attempts' => $maxAttempts],
        );
    }

    // ── Hydration ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function hydrateRow(array $row): OutboxEvent
    {
        $utc = new \DateTimeZone('UTC');

        return new OutboxEvent(
            id:            OutboxEventId::fromString((string) $row['id']),
            aggregateType: (string) $row['aggregate_type'],
            aggregateId:   (string) $row['aggregate_id'],
            eventType:     (string) $row['event_type'],
            payload:       json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR),
            occurredAt:    new \DateTimeImmutable((string) $row['occurred_at'], $utc),
            createdAt:     new \DateTimeImmutable((string) $row['created_at'], $utc),
            publishedAt:   $row['published_at'] !== null
                               ? new \DateTimeImmutable((string) $row['published_at'], $utc)
                               : null,
            attemptCount:  (int) $row['attempt_count'],
            lastError:     $row['last_error'] !== null ? (string) $row['last_error'] : null,
        );
    }
}
