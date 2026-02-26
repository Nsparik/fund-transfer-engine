<?php

declare(strict_types=1);

namespace App\Shared\Domain\Outbox;

use Symfony\Component\Uid\UuidV7;

/**
 * Value object identifying a single outbox event row.
 *
 * UUID v7 — time-ordered, B-tree efficient for the polling index on
 * (published_at, created_at). Every new event ID sorts after all previously
 * inserted IDs, minimising page splits on the PRIMARY KEY index.
 */
final class OutboxEventId
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        return new self((new UuidV7())->toRfc4122());
    }

    public static function fromString(string $value): self
    {
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        )) {
            throw new \InvalidArgumentException(
                sprintf('Invalid OutboxEventId UUID: "%s".', $value),
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
