<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Port\TransactionManagerInterface;
use App\Shared\Domain\Outbox\OutboxEventSerializerInterface;
use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Polls the outbox table and dispatches unpublished domain events.
 *
 * ## Retry & dead-letter policy
 *   - Events are retried up to MAX_ATTEMPTS times.
 *   - On the MAX_ATTEMPTS-th failure the event is logged at CRITICAL level
 *     and permanently skipped (dead-lettered). It is NOT deleted from the DB
 *     so operators can inspect and manually replay.
 *   - Operator action: fix the downstream consumer, then set published_at = NULL
 *     and attempt_count = 0 to re-queue.
 *
 * ## Concurrency — SKIP LOCKED requires a surrounding transaction
 *   findUnpublished() uses SELECT … FOR UPDATE SKIP LOCKED. In MySQL, row-level
 *   locks acquired by FOR UPDATE are released at transaction end. Without a
 *   surrounding explicit transaction (auto-commit mode), each statement is its
 *   own micro-transaction and the locks are released immediately after the
 *   SELECT returns — making SKIP LOCKED completely ineffective.
 *
 *   pollAndPublish() therefore wraps the entire batch (SELECT + all markPublished /
 *   markFailed calls) in a single DB transaction. Concurrent workers will receive
 *   disjoint event batches, guaranteeing exactly-once processing per batch.
 *
 * ## Usage
 *   Called from ProcessOutboxCommand (long-running daemon or cron one-shot).
 */
final class OutboxProcessor
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly OutboxRepositoryInterface   $outbox,
        private readonly EventDispatcherInterface    $dispatcher,
        private readonly OutboxEventSerializerInterface $serializer,
        private readonly LoggerInterface             $logger,
        private readonly TransactionManagerInterface $transactionManager,
    ) {}

    /**
     * Poll one batch and attempt to publish each event.
     *
     * The entire batch runs inside a single DB transaction so that:
     *   1. FOR UPDATE SKIP LOCKED row locks are held until commit.
     *   2. markPublished / markFailed writes are atomic with the lock release.
     *
     * @return int Number of events successfully published in this batch.
     */
    public function pollAndPublish(int $batchSize = 100): int
    {
        return (int) $this->transactionManager->transactional(
            function () use ($batchSize): int {
                $events    = $this->outbox->findUnpublished($batchSize);
                $published = 0;

                foreach ($events as $outboxEvent) {
                    // Dead-letter check — event has already exhausted all retries.
                    if ($outboxEvent->attemptCount >= self::MAX_ATTEMPTS) {
                        $this->logger->critical('outbox.dead_letter', [
                            'outbox_event_id' => $outboxEvent->id->toString(),
                            'event_type'      => $outboxEvent->eventType,
                            'aggregate_id'    => $outboxEvent->aggregateId,
                            'aggregate_type'  => $outboxEvent->aggregateType,
                            'attempt_count'   => $outboxEvent->attemptCount,
                            'last_error'      => $outboxEvent->lastError,
                        ]);
                        continue;
                    }

                    try {
                        $domainEvent = $this->serializer->deserialize($outboxEvent);
                        $this->dispatcher->dispatch($domainEvent);
                        $this->outbox->markPublished($outboxEvent->id);
                        ++$published;

                        $this->logger->info('outbox.published', [
                            'outbox_event_id' => $outboxEvent->id->toString(),
                            'event_type'      => $outboxEvent->eventType,
                            'aggregate_id'    => $outboxEvent->aggregateId,
                        ]);
                    } catch (\Throwable $dispatchError) {
                        // Dispatch or deserialization failed — record the failure.
                        // markFailed runs inside the same transaction so the increment
                        // is atomic. If markFailed itself throws, the transaction rolls
                        // back and the attempt_count is NOT incremented — the event
                        // will be retried on the next poll (correct at-least-once behaviour).
                        try {
                            $this->outbox->markFailed($outboxEvent->id, $dispatchError->getMessage());
                        } catch (\Throwable $markFailedError) {
                            $this->logger->error('outbox.mark_failed_error', [
                                'outbox_event_id' => $outboxEvent->id->toString(),
                                'event_type'      => $outboxEvent->eventType,
                                'error_class'     => $markFailedError::class,
                                'error_message'   => $markFailedError->getMessage(),
                            ]);
                            // Re-throw to abort the transaction — the whole batch will
                            // be retried fresh on the next poll cycle.
                            throw $markFailedError;
                        }

                        $this->logger->warning('outbox.dispatch_failed', [
                            'outbox_event_id' => $outboxEvent->id->toString(),
                            'event_type'      => $outboxEvent->eventType,
                            'aggregate_id'    => $outboxEvent->aggregateId,
                            'attempt_count'   => $outboxEvent->attemptCount + 1,
                            'error_class'     => $dispatchError::class,
                            'error_message'   => $dispatchError->getMessage(),
                        ]);
                    }
                }

                return $published;
            }
        );
    }
}
