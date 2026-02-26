<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Unique identifier for a Transfer aggregate.
 *
 * Internally stored as a lowercase UUID string.
 * New IDs are generated as UUIDv7 (time-ordered) for efficient B-tree
 * index insertion and natural chronological sorting in MySQL.
 */
final class TransferId
{
    private function __construct(private readonly string $value) {}

    /**
     * Generate a new, time-ordered transfer ID.
     */
    public static function generate(): self
    {
        return new self((string) new UuidV7());
    }

    /**
     * Reconstitute from a stored UUID string (any version accepted).
     *
     * @throws \InvalidArgumentException if the string is not a valid UUID
     */
    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid TransferId "%s": value must be a valid UUID.', $value)
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
