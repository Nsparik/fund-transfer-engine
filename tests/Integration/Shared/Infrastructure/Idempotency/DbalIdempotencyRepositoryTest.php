<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Infrastructure\Idempotency;

use App\Shared\Domain\Idempotency\IdempotencyRecord;
use App\Shared\Infrastructure\Idempotency\DbalIdempotencyRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DbalIdempotencyRepository against the real MySQL container.
 *
 * Builds a raw DBAL Connection from DATABASE_URL (populated by bootEnv() in
 * bootstrap.php) — no Symfony kernel required.
 * Each test cleans up its own rows in tearDown().
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class DbalIdempotencyRepositoryTest extends TestCase
{
    private DbalIdempotencyRepository $repository;
    private Connection $connection;

    /** @var list<string> Idempotency keys to delete in tearDown */
    private array $insertedKeys = [];

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection = DriverManager::getConnection(['url' => $url]);
        $this->repository = new DbalIdempotencyRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->insertedKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($this->insertedKeys), '?'));
            $this->connection->executeStatement(
                "DELETE FROM idempotency_keys WHERE idempotency_key IN ({$placeholders})",
                $this->insertedKeys,
            );
            $this->insertedKeys = [];
        }
    }

    // ── save() + findByKey() round-trip ──────────────────────────────────────

    public function testSaveAndFindByKeyRoundTrip(): void
    {
        $record = $this->makeRecord('idem-key-001', 200, ['data' => ['id' => 'tx-1']]);
        $this->repository->save($record);
        $this->track('idem-key-001');

        $found = $this->repository->findByKey('idem-key-001');

        self::assertNotNull($found);
        self::assertSame('idem-key-001', $found->idempotencyKey);
        self::assertSame(200, $found->responseStatus);
        self::assertSame(['data' => ['id' => 'tx-1']], $found->responseBody);
        self::assertSame($record->requestHash, $found->requestHash);
    }

    public function testFindByKeyReturnsNullForUnknownKey(): void
    {
        self::assertNull($this->repository->findByKey('nonexistent-key-xyz'));
    }

    // ── TTL: expired records are invisible ───────────────────────────────────

    public function testFindByKeyReturnsNullForExpiredRecord(): void
    {
        // Insert a row with an expires_at in the past directly via SQL
        $this->connection->executeStatement(
            'INSERT INTO idempotency_keys
                (idempotency_key, request_hash, response_status, response_body, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                'idem-key-expired',
                hash('sha256', 'body'),
                200,
                json_encode(['data' => []]),
                (new \DateTimeImmutable('-25 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                (new \DateTimeImmutable('-1 hour',   new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ],
        );
        $this->track('idem-key-expired');

        // Repository filters out expired rows — must return null
        self::assertNull($this->repository->findByKey('idem-key-expired'));
    }

    // ── INSERT IGNORE: second save does not overwrite ─────────────────────────

    public function testSaveWithInsertIgnoreDoesNotOverwriteExistingRecord(): void
    {
        $original = $this->makeRecord('idem-key-002', 201, ['data' => ['status' => 'COMPLETED']]);
        $this->repository->save($original);
        $this->track('idem-key-002');

        // Attempt to overwrite with a different response — INSERT IGNORE must discard it.
        $overwrite = new IdempotencyRecord(
            idempotencyKey: 'idem-key-002',
            requestHash:    hash('sha256', 'different-body'),
            responseStatus: 422,
            responseBody:   ['error' => ['code' => 'FAKE']],
            createdAt:      new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            expiresAt:      new \DateTimeImmutable('+24 hours', new \DateTimeZone('UTC')),
        );
        $this->repository->save($overwrite);

        $found = $this->repository->findByKey('idem-key-002');

        self::assertNotNull($found);
        self::assertSame(201, $found->responseStatus, 'Original record must not be overwritten');
        self::assertSame(['data' => ['status' => 'COMPLETED']], $found->responseBody);
    }

    // ── deleteExpired() ───────────────────────────────────────────────────────

    public function testDeleteExpiredRemovesOnlyExpiredRows(): void
    {
        $activeKey  = 'idem-active-' . uniqid();
        $expiredKey = 'idem-expired-' . uniqid();

        // Insert a live record via the repository
        $active = $this->makeRecord($activeKey, 200, ['data' => []]);
        $this->repository->save($active);
        $this->track($activeKey);

        // Insert an expired record directly via SQL
        $this->connection->executeStatement(
            'INSERT INTO idempotency_keys
                (idempotency_key, request_hash, response_status, response_body, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $expiredKey,
                hash('sha256', 'body'),
                200,
                json_encode(['data' => []]),
                (new \DateTimeImmutable('-25 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                (new \DateTimeImmutable('-1 hour',   new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ],
        );
        // Note: we do NOT track $expiredKey because deleteExpired() should remove it for us.

        $deleted = $this->repository->deleteExpired();

        self::assertGreaterThanOrEqual(1, $deleted, 'At least one expired row should have been deleted');

        // Expired row is gone
        $row = $this->connection->fetchAssociative(
            'SELECT idempotency_key FROM idempotency_keys WHERE idempotency_key = ?',
            [$expiredKey],
        );
        self::assertFalse($row, 'Expired row must be deleted');

        // Active row is still there
        self::assertNotNull($this->repository->findByKey($activeKey), 'Non-expired row must remain');
    }

    // ── Response body serialisation ───────────────────────────────────────────

    public function testResponseBodyIsSerializedAndDeserializedCorrectly(): void
    {
        $body = [
            'data' => [
                'id'           => 'transfer-uuid-123',
                'status'       => 'COMPLETED',
                'amount'       => 1050,
                'currency'     => 'USD',
                'source_id'    => 'account-uuid-src',
                'destination'  => 'account-uuid-dst',
            ],
        ];

        $record = $this->makeRecord('idem-key-json', 201, $body);
        $this->repository->save($record);
        $this->track('idem-key-json');

        $found = $this->repository->findByKey('idem-key-json');

        self::assertEquals($body, $found->responseBody);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function makeRecord(string $key, int $status, array $body): IdempotencyRecord
    {
        return IdempotencyRecord::create(
            idempotencyKey: $key,
            requestHash:    hash('sha256', json_encode($body)),
            responseStatus: $status,
            responseBody:   $body,
        );
    }

    private function track(string $key): void
    {
        $this->insertedKeys[] = $key;
    }
}
