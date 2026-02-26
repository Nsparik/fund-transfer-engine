<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Domain\Idempotency\IdempotencyRecord;
use App\Shared\Domain\Idempotency\IdempotencyRepositoryInterface;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed implementation of IdempotencyRepositoryInterface.
 *
 * Uses the idempotency_keys table created by
 * Version20260224000003CreateIdempotencyKeysTable.
 *
 * ## Race condition on concurrent first-requests
 *   Two concurrent requests with the same key could both pass the findByKey()
 *   check simultaneously before either has saved.  We handle this by using
 *   INSERT IGNORE — only one row wins; the second request's save() is silently
 *   discarded.  Both callers return their own response, but only the first
 *   response is cached.  This is safe: both responses are identical for the
 *   same key+hash, and the second caller will see the cached row on its next
 *   retry anyway.
 */
final class DbalIdempotencyRepository implements IdempotencyRepositoryInterface
{
    private const TABLE           = 'idempotency_keys';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function findByKey(string $idempotencyKey): ?IdempotencyRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT idempotency_key, request_hash, response_status, response_body,'
            . ' created_at, expires_at'
            . ' FROM ' . self::TABLE
            . ' WHERE idempotency_key = ? AND expires_at > NOW(6)',
            [$idempotencyKey],
        );

        if ($row === false) {
            return null;
        }

        return new IdempotencyRecord(
            idempotencyKey: (string) $row['idempotency_key'],
            requestHash:    (string) $row['request_hash'],
            responseStatus: (int)    $row['response_status'],
            responseBody:   json_decode((string) $row['response_body'], true, 512, JSON_THROW_ON_ERROR),
            createdAt:      $this->parseDateTime((string) $row['created_at']),
            expiresAt:      $this->parseDateTime((string) $row['expires_at']),
        );
    }

    public function save(IdempotencyRecord $record): void
    {
        // INSERT IGNORE handles the rare race where two identical first-requests
        // arrive simultaneously — only one row is persisted.
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT IGNORE INTO idempotency_keys
                (idempotency_key, request_hash, response_status, response_body, created_at, expires_at)
            VALUES
                (:key, :hash, :status, :body, :created_at, :expires_at)
            SQL,
            [
                'key'        => $record->idempotencyKey,
                'hash'       => $record->requestHash,
                'status'     => $record->responseStatus,
                'body'       => json_encode($record->responseBody, JSON_THROW_ON_ERROR),
                'created_at' => $record->createdAt->format(self::DATETIME_FORMAT),
                'expires_at' => $record->expiresAt->format(self::DATETIME_FORMAT),
            ],
        );
    }

    public function deleteExpired(): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at <= NOW(6)'
        );
    }

    /**
     * Acquires a MySQL GET_LOCK() advisory lock keyed to the idempotency key.
     *
     * GET_LOCK() is connection-scoped: two requests sharing a connection
     * (impossible in a standard PHP-FPM process-per-request model, but
     * theoretically possible with connection pooling) would re-enter the
     * same lock safely because MySQL GET_LOCK() is re-entrant per connection.
     *
     * The lock name is prefixed with "idempotency:" and SHA-256 hashed to
     * ensure it fits within MySQL's 64-character lock name limit regardless
     * of the length of the raw idempotency key.
     */
    public function acquireLock(string $idempotencyKey, int $timeoutSeconds = 5): bool
    {
        $lockName = $this->lockName($idempotencyKey);
        $result   = $this->connection->fetchOne(
            'SELECT GET_LOCK(?, ?)',
            [$lockName, $timeoutSeconds],
        );

        return $result === '1' || $result === 1;
    }

    public function releaseLock(string $idempotencyKey): void
    {
        $lockName = $this->lockName($idempotencyKey);
        $this->connection->executeStatement(
            'SELECT RELEASE_LOCK(?)',
            [$lockName],
        );
    }

    /**
     * Produce a MySQL-safe lock name from the idempotency key.
     *
     * MySQL GET_LOCK() names are limited to 64 bytes.  SHA-256 produces
     * a 64-character hex string which fits exactly.  The "idp:" prefix
     * namespaces these locks away from any other GET_LOCK() users in the
     * same MySQL instance, but since the final name is the hash of the
     * prefixed key the resulting string is still 64 chars.
     */
    private function lockName(string $idempotencyKey): string
    {
        return hash('sha256', 'idp:' . $idempotencyKey);
    }

    private function parseDateTime(string $value): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value, new \DateTimeZone('UTC'));

        if ($dt !== false) {
            return $dt;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
