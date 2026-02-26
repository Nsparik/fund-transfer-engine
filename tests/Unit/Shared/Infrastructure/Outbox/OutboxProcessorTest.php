<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Outbox;

use App\Module\Transfer\Domain\Event\TransferInitiated;
use App\Shared\Application\Port\TransactionManagerInterface;
use App\Shared\Domain\Outbox\OutboxEvent;
use App\Shared\Domain\Outbox\OutboxEventId;
use App\Shared\Domain\Outbox\OutboxEventSerializerInterface;
use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use App\Shared\Infrastructure\Outbox\OutboxProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for OutboxProcessor.
 *
 * OutboxEventSerializer is `final` and has no I/O — a real instance is used.
 * All other collaborators (OutboxRepositoryInterface, EventDispatcherInterface,
 * TransactionManagerInterface, LoggerInterface) are mocked/stubbed or faked inline.
 *
 * Tests cover:
 *   1. Happy path — deserialize → dispatch → markPublished → INFO log
 *   2. Dispatch failure — markFailed + WARNING log; not counted as published
 *   3. Dead-letter — attemptCount >= MAX_ATTEMPTS → CRITICAL log; skipped entirely
 *   4. Mixed batch — correct published count across all three scenarios
 *   5. Empty batch — returns 0 immediately
 */
final class OutboxProcessorTest extends TestCase
{
    private OutboxEventSerializerInterface $serializer;
    private TransactionManagerInterface $txManager;

    protected function setUp(): void
    {
        $this->serializer = new \App\Shared\Infrastructure\Outbox\OutboxEventSerializer();

        // Passthrough fake: executes the callable synchronously, no DB transaction.
        $this->txManager = new class implements TransactionManagerInterface {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build an OutboxEvent whose payload is valid for deserialization as TransferInitiated.
     */
    private function makeValidOutboxEvent(int $attemptCount = 0, ?string $lastError = null): OutboxEvent
    {
        return new OutboxEvent(
            id:            OutboxEventId::generate(),
            aggregateType: 'Transfer',
            aggregateId:   'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            eventType:     TransferInitiated::class,
            payload:       [
                'transfer_id'            => 'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
                'reference'              => 'TXN-20260101-AABBCCDDEEFF',
                'source_account_id'      => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'destination_account_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'amount_minor_units'     => 1_000,
                'currency'               => 'USD',
                'occurred_at'            => '2026-01-01T00:00:00+00:00',
            ],
            occurredAt:    new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            createdAt:     new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            attemptCount:  $attemptCount,
            lastError:     $lastError,
        );
    }

    /**
     * Build a dead-letter OutboxEvent (attempt_count == MAX_ATTEMPTS).
     * Payload is intentionally empty — deserialization is never reached.
     */
    private function makeDeadLetterOutboxEvent(): OutboxEvent
    {
        return new OutboxEvent(
            id:            OutboxEventId::generate(),
            aggregateType: 'Transfer',
            aggregateId:   'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            eventType:     'Transfer\\SomeDomainEvent',
            payload:       [],
            occurredAt:    new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            createdAt:     new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            attemptCount:  5,
            lastError:     'fifth failure',
        );
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function testHappyPathDispatchesAndMarksPublished(): void
    {
        $outboxEvent = $this->makeValidOutboxEvent(0);

        $outbox = $this->createMock(OutboxRepositoryInterface::class);
        $outbox->method('findUnpublished')->willReturn([$outboxEvent]);
        $outbox->expects(self::once())->method('markPublished')->with($outboxEvent->id);
        $outbox->expects(self::never())->method('markFailed');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TransferInitiated::class))
            ->willReturnArgument(0);

        $processor = new OutboxProcessor($outbox, $dispatcher, $this->serializer, new NullLogger(), $this->txManager);

        self::assertSame(1, $processor->pollAndPublish());
    }

    public function testHappyPathLogsInfoPerPublishedEvent(): void
    {
        $outboxEvent = $this->makeValidOutboxEvent(0);

        $outbox = $this->createStub(OutboxRepositoryInterface::class);
        $outbox->method('findUnpublished')->willReturn([$outboxEvent]);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'outbox.published',
                self::callback(fn(array $ctx) => isset($ctx['outbox_event_id'], $ctx['event_type'], $ctx['aggregate_id'])),
            );

        $processor = new OutboxProcessor($outbox, $dispatcher, $this->serializer, $logger, $this->txManager);
        $processor->pollAndPublish();
    }

    // ── Dispatch failure ──────────────────────────────────────────────────────

    public function testDispatchExceptionCallsMarkFailedWithExactMessage(): void
    {
        $outboxEvent = $this->makeValidOutboxEvent(0);

        $outbox = $this->createMock(OutboxRepositoryInterface::class);
        $outbox->method('findUnpublished')->willReturn([$outboxEvent]);
        $outbox->expects(self::never())->method('markPublished');
        $outbox->expects(self::once())->method('markFailed')
            ->with($outboxEvent->id, 'downstream exploded');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('downstream exploded'));

        $processor = new OutboxProcessor($outbox, $dispatcher, $this->serializer, new NullLogger(), $this->txManager);

        self::assertSame(0, $processor->pollAndPublish());
    }

    public function testDispatchExceptionLogsWarningWithNextAttemptCount(): void
    {
        $outboxEvent = $this->makeValidOutboxEvent(2);

        $outbox = $this->createStub(OutboxRepositoryInterface::class);
        $outbox->method('findUnpublished')->willReturn([$outboxEvent]);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('network timeout'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'outbox.dispatch_failed',
                self::callback(fn(array $ctx) => $ctx['attempt_count'] === 3),
            );

        $processor = new OutboxProcessor($outbox, $dispatcher, $this->serializer, $logger, $this->txManager);
        $processor->pollAndPublish();
    }

    // ── Dead-letter ───────────────────────────────────────────────────────────

    public function testDeadLetterEventIsSkippedAndNeitherPublishedNorFailed(): void
    {
        $deadEvent = $this->makeDeadLetterOutboxEvent();

        $outbox = $this->createMock(OutboxRepositoryInterface::class);
        $outbox->method('findUnpublished')->willReturn([$deadEvent]);
        $outbox->expects(self::never())->method('markPublished');
        $outbox->expects(self::never())->method('markFailed');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $processor = new OutboxProcessor($outbox, $dispatcher, $this->serializer, new NullLogger(), $this->txManager);

        self::assertSame(0, $processor->pollAndPublish());
    }

    public function testDeadLetterEventLogsAtCriticalLevelWithContext(): void
    {
        $deadEvent = $this->makeDeadLetterOutboxEvent();

        $outbox = $this->createStub(OutboxRepositoryInterface::class);
        $outbox->method('findUnpublished')->willReturn([$deadEvent]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('critical')
            ->with(
                'outbox.dead_letter',
                self::callback(fn(array $ctx) => $ctx['attempt_count'] === 5 && $ctx['last_error'] === 'fifth failure'),
            );

        $processor = new OutboxProcessor(
            $outbox,
            $this->createStub(EventDispatcherInterface::class),
            $this->serializer,
            $logger,
            $this->txManager,
        );

        $processor->pollAndPublish();
    }

    // ── Mixed batch ───────────────────────────────────────────────────────────

    public function testMixedBatchCountsOnlyPublishedEvents(): void
    {
        $goodEvent = $this->makeValidOutboxEvent(0);
        $failEvent = $this->makeValidOutboxEvent(1);
        $deadEvent = $this->makeDeadLetterOutboxEvent();

        $dispatchCallCount = 0;

        $outbox = $this->createMock(OutboxRepositoryInterface::class);
        $outbox->method('findUnpublished')->willReturn([$goodEvent, $failEvent, $deadEvent]);
        $outbox->expects(self::once())->method('markPublished')->with($goodEvent->id);
        $outbox->expects(self::once())->method('markFailed')
            ->with($failEvent->id, 'second subscriber failed');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $e) use (&$dispatchCallCount): object {
                ++$dispatchCallCount;
                if ($dispatchCallCount === 2) {
                    throw new \RuntimeException('second subscriber failed');
                }
                return $e;
            });

        $processor = new OutboxProcessor($outbox, $dispatcher, $this->serializer, new NullLogger(), $this->txManager);

        self::assertSame(1, $processor->pollAndPublish());
    }

    // ── Empty batch ─────────────────────────────────────────────────────────────────────────────

    public function testEmptyBatchReturnsZeroWithNoInteractions(): void
    {
        $outbox = $this->createStub(OutboxRepositoryInterface::class);
        $outbox->method('findUnpublished')->willReturn([]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $processor = new OutboxProcessor($outbox, $dispatcher, $this->serializer, new NullLogger(), $this->txManager);

        self::assertSame(0, $processor->pollAndPublish());
    }
}
