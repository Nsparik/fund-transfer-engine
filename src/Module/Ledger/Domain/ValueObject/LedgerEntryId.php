<?php

declare(strict_types=1);

namespace App\Module\Ledger\Domain\ValueObject;

use Symfony\Component\Uid\UuidV7;

/**
 * LedgerEntryId — unique identifier for a LedgerEntry.
 *
 * Uses UUIDv7 (time-ordered) for the same reason as TransferId:
 * ledger entries are internal, immutable, append-only records.
 * Time-ordering ensures B-tree efficiency on the primary key
 * and allows log-style sequential reads.
 *
 * Plain PHP — no framework dependencies (Symfony\Component\Uid is a
 * composer dependency, not a framework service).
 */
final readonly class LedgerEntryId
{
    private function __construct(
        private string $value,
    ) {}

    /**
     * Generate a new UUIDv7 ledger entry ID.
     * Call ONLY from LedgerEntry factory methods — not from tests or handlers.
     */
    public static function generate(): self
    {
        return new self((new UuidV7())->toRfc4122());
    }

    /**
     * Parse any valid UUID string (v4, v7, legacy fixtures).
     *
     * @throws \InvalidArgumentException when the string is not a valid UUID
     */
    public static function fromString(string $value): self
    {
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        )) {
            throw new \InvalidArgumentException(
                sprintf('Invalid LedgerEntryId: "%s" is not a valid UUID.', $value),
            );
        }

        return new self(strtolower($value));
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
