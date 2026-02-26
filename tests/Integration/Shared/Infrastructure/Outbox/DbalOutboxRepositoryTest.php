<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Infrastructure\Outbox;

use App\Module\Transfer\Domain\Event\TransferInitiated;
use App\Shared\Domain\Outbox\OutboxEvent;
use App\Shared\Domain\Outbox\OutboxEventId;
use App\Shared\Infrastructure\Outbox\DbalOutboxRepository;
use App\Shared\Infrastructure\Outbox\OutboxEventSerializer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DbalOutboxRepository against the real MySQL container.
 *
 * Verifies:
 *   — save() persists an OutboxEvent row with correct fields
 *   — findUnpublished() returns only rows where published_at IS NULL
 *   — findUnpublished() respects LIMIT
 *   — findUnpublished() returns rows ordered by created_at ASC
 *   — markPublished() sets published_at and removes the row from unpublished results
 *   — markFailed() increments attempt_count and sets last_error
 *   — markFailed() successive calls accumulate attempt_count
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class DbalOutboxRepositoryTest extends TestCase
{
    private Connection          $connection;
    private DbalOutboxRepository $repo;

    /** @var list<string> OutboxEvent IDs to delete in tearDown */
    private array $outboxIds = [];

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection = DriverManager::getConnection(['url' => $url]);
        $this->repo       = new DbalOutboxRepository($this->connection);

        // Purge all stale outbox rows left by previous test runs or other test classes.
        // Each test in this class requires a known-empty table to reason about LIMIT and
        // ordering — without this reset, unpublished rows from prior runs pollute results.
        $this->connection->executeStatement('DELETE FROM outbox_events');
    }

    protected function tearDown(): void
    {
        if ($this->outboxIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->outboxIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM outbox_events WHERE id IN ($placeholders)",
                $this->outboxIds,
            );
        }

        $this->connection->close();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOutboxEvent(int $attemptCount = 0, ?string $createdAtOffset = null): OutboxEvent
    {
        $id  = OutboxEventId::generate();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($createdAtOffset !== null) {
            $now = $now->modify($createdAtOffset);
        }

        $this->outboxIds[] = $id->toString();

        return new OutboxEvent(
            id:            $id,
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
            createdAt:     $now,
            attemptCount:  $attemptCount,
        );
    }

    // ── save + findUnpublished round-trip ─────────────────────────────────────

    public function testSaveAndFindUnpublishedRoundTrip(): void
    {
        $event = $this->makeOutboxEvent();
        $this->repo->save($event);

        $results = $this->repo->findUnpublished(10);
        $ids     = array_map(fn(OutboxEvent $e) => $e->id->toString(), $results);

        self::assertContains($event->id->toString(), $ids);

        $found = current(array_filter($results, fn(OutboxEvent $e) => $e->id->toString() === $event->id->toString()));
        self::assertNotFalse($found);
        self::assertSame('Transfer', $found->aggregateType);
        self::assertSame(TransferInitiated::class, $found->eventType);
        self::assertSame(0, $found->attemptCount);
        self::assertNull($found->publishedAt);
    }

    public function testSavedPayloadIsPersistedAndHydrated(): void
    {
        $event = $this->makeOutboxEvent();
        $this->repo->save($event);

        $results = $this->repo->findUnpublished(10);
        $found   = current(array_filter($results, fn(OutboxEvent $e) => $e->id->toString() === $event->id->toString()));
        self::assertNotFalse($found);

        // The serializer must be able to reconstruct the domain event from the persisted payload.
        $serializer  = new OutboxEventSerializer();
        $domainEvent = $serializer->deserialize($found);

        self::assertInstanceOf(TransferInitiated::class, $domainEvent);
    }

    // ── markPublished ─────────────────────────────────────────────────────────

    public function testMarkPublishedRemovesRowFromUnpublishedResults(): void
    {
        $event = $this->makeOutboxEvent();
        $this->repo->save($event);

        $this->repo->markPublished($event->id);

        $results = $this->repo->findUnpublished(10);
        $ids     = array_map(fn(OutboxEvent $e) => $e->id->toString(), $results);

        self::assertNotContains($event->id->toString(), $ids);
    }

    public function testMarkPublishedSetsPublishedAtTimestamp(): void
    {
        $event = $this->makeOutboxEvent();
        $this->repo->save($event);

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->repo->markPublished($event->id);
        $after  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $row = $this->connection->fetchAssociative(
            'SELECT published_at FROM outbox_events WHERE id = ?',
            [$event->id->toString()],
        );

        self::assertNotFalse($row);
        self::assertNotNull($row['published_at']);

        $publishedAt = new \DateTimeImmutable((string) $row['published_at'], new \DateTimeZone('UTC'));
        self::assertGreaterThanOrEqual($before, $publishedAt);
        self::assertLessThanOrEqual($after, $publishedAt);
    }

    // ── markFailed ────────────────────────────────────────────────────────────

    public function testMarkFailedIncrementsAttemptCountAndSetsLastError(): void
    {
        $event = $this->makeOutboxEvent(0);
        $this->repo->save($event);

        $this->repo->markFailed($event->id, 'connection refused');

        $row = $this->connection->fetchAssociative(
            'SELECT attempt_count, last_error FROM outbox_events WHERE id = ?',
            [$event->id->toString()],
        );

        self::assertNotFalse($row);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame('connection refused', $row['last_error']);
    }

    public function testMarkFailedAccumulatesAttemptCountAcrossCalls(): void
    {
        $event = $this->makeOutboxEvent(0);
        $this->repo->save($event);

        $this->repo->markFailed($event->id, 'first failure');
        $this->repo->markFailed($event->id, 'second failure');
        $this->repo->markFailed($event->id, 'third failure');

        $row = $this->connection->fetchAssociative(
            'SELECT attempt_count, last_error FROM outbox_events WHERE id = ?',
            [$event->id->toString()],
        );

        self::assertNotFalse($row);
        self::assertSame(3, (int) $row['attempt_count']);
        self::assertSame('third failure', $row['last_error']);
    }

    // ── findUnpublished ordering ──────────────────────────────────────────────

    public function testFindUnpublishedReturnsRowsOrderedByCreatedAtAsc(): void
    {
        // Create events with different created_at offsets — order must be ascending.
        $older  = $this->makeOutboxEvent(0, '-2 seconds');
        $middle = $this->makeOutboxEvent(0, '-1 second');
        $newer  = $this->makeOutboxEvent(0);

        // Save in a random order.
        $this->repo->save($newer);
        $this->repo->save($older);
        $this->repo->save($middle);

        $results = $this->repo->findUnpublished(10);

        // Filter to only the IDs we created in this test.
        $testIds    = [$older->id->toString(), $middle->id->toString(), $newer->id->toString()];
        $ourResults = array_values(array_filter($results, fn(OutboxEvent $e) => in_array($e->id->toString(), $testIds, true)));

        self::assertCount(3, $ourResults);
        self::assertSame($older->id->toString(),  $ourResults[0]->id->toString());
        self::assertSame($middle->id->toString(), $ourResults[1]->id->toString());
        self::assertSame($newer->id->toString(),  $ourResults[2]->id->toString());
    }

    // ── findUnpublished limit ─────────────────────────────────────────────────

    public function testFindUnpublishedRespectsLimit(): void
    {
        $ev1 = $this->makeOutboxEvent(0, '-3 seconds');
        $ev2 = $this->makeOutboxEvent(0, '-2 seconds');
        $ev3 = $this->makeOutboxEvent(0, '-1 second');

        $this->repo->save($ev1);
        $this->repo->save($ev2);
        $this->repo->save($ev3);

        // Limit=2 should only return 2 (could be any 2, but for our isolated case we
        // need to only check that we get at most 2 from the full table).
        $results = $this->repo->findUnpublished(2);

        self::assertLessThanOrEqual(2, count($results));
    }
}
