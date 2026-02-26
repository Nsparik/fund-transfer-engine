<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency;

/**
 * Immutable value object representing a stored idempotency record.
 *
 * One row per unique X-Idempotency-Key submitted to POST /transfers.
 * Holds the original response so identical retries get the exact same result
 * without re-executing the transfer handler (preventing double-debit).
 *
 * ## Conflict detection
 *   requestHash is the SHA-256 of the request body at the time of the first
 *   call.  If a later request presents the same idempotency key but a
 *   different body hash, the subscriber rejects it with HTTP 422
 *   IDEMPOTENCY_KEY_REUSE.  This catches copy-paste bugs in client code.
 *
 * ## TTL
 *   Records expire 24 hours after creation (stored in expires_at).
 *   A nightly cron runs app:idempotency:prune to delete expired rows.
 */
final class IdempotencyRecord
{
    public function __construct(
        public readonly string             $idempotencyKey,
        public readonly string             $requestHash,
        public readonly int                $responseStatus,
        public readonly array              $responseBody,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $expiresAt,
    ) {}

    /**
     * @param int $ttlHours Configurable TTL in hours (default 24).
     *                       Increase for batch/settlement systems that may retry
     *                       on next-day schedules.
     */
    public static function create(
        string $idempotencyKey,
        string $requestHash,
        int    $responseStatus,
        array  $responseBody,
        int    $ttlHours = 24,
    ): self {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new self(
            idempotencyKey: $idempotencyKey,
            requestHash:    $requestHash,
            responseStatus: $responseStatus,
            responseBody:   $responseBody,
            createdAt:      $now,
            expiresAt:      $now->modify(sprintf('+%d hours', max(1, $ttlHours))),
        );
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Returns true when the given request body hash matches the stored one.
     * A mismatch means the client reused the key with a different payload.
     */
    public function matchesRequestHash(string $hash): bool
    {
        return hash_equals($this->requestHash, $hash);
    }
}
