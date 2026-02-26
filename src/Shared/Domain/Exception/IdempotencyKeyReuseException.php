<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Thrown when a client reuses an X-Idempotency-Key with a different request body.
 *
 * This is always a client bug — the same key must map to identical parameters.
 * Rejected with HTTP 422 (default mapping in DomainExceptionListener).
 *
 * Example: client sends POST /transfers with key="abc" and amount=100,
 * then retries with key="abc" and amount=200 — this is rejected immediately.
 */
final class IdempotencyKeyReuseException extends \RuntimeException implements DomainExceptionInterface
{
    public function getDomainCode(): string
    {
        return 'IDEMPOTENCY_KEY_REUSE';
    }
}
