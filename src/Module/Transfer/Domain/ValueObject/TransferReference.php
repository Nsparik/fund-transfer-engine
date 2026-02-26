<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\ValueObject;

/**
 * Human-readable unique reference for a Transfer.
 *
 * ## Format
 *   TXN-{YYYYMMDD}-{12 hex chars}
 *   e.g. TXN-20260224-1176CBAF2F84
 *
 * ## Generation strategy
 *   The last 12 hex characters of the TransferId (a UUIDv7) come from the
 *   rand_b field — 46 bits of cryptographic randomness.  Birthday-problem
 *   collision probability: ~50% only after ~10^6 transfers per day, which
 *   is far beyond the scope of this system.  The DB enforces UNIQUE on this
 *   column as a final safety net.
 *
 * ## Purpose
 *   Customers quote this reference in support tickets and dispute filings,
 *   NOT the internal UUID.  It also appears on bank statements and audit
 *   reports.  Human-readable but still traceable to the TransferId.
 */
final class TransferReference
{
    // TXN-YYYYMMDD-XXXXXXXXXXXX  (X = uppercase hex, exactly 12 chars)
    private const PATTERN = '/^TXN-\d{8}-[0-9A-F]{12}$/';

    private function __construct(private readonly string $value) {}

    /**
     * Generate a reference deterministically from a transfer ID and timestamp.
     *
     * Takes the last 12 hex characters of the UUID (rand_b bits of UUIDv7)
     * to maximise randomness while keeping the reference short.
     */
    public static function generate(TransferId $id, \DateTimeImmutable $createdAt): self
    {
        $date   = $createdAt->format('Ymd');
        // Last 12 hex chars of the UUID (strip dashes first)
        $suffix = strtoupper(substr(str_replace('-', '', $id->toString()), -12));

        return new self(sprintf('TXN-%s-%s', $date, $suffix));
    }

    /**
     * Reconstitute from a stored reference string.
     *
     * @throws \InvalidArgumentException on malformed reference
     */
    public static function fromString(string $value): self
    {
        if (!preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid TransferReference "%s": expected format TXN-YYYYMMDD-XXXXXXXXXXXX.',
                    $value,
                )
            );
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
