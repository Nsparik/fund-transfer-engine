<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * Identifies a bank or wallet account.
 *
 * Structurally identical to TransferId but intentionally a distinct type
 * so the compiler can catch mix-ups between transfer IDs and account IDs.
 */
final class AccountId
{
    private function __construct(private readonly string $value) {}

    /**
     * @throws \InvalidArgumentException if the string is not a valid UUID
     */
    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid AccountId "%s": value must be a valid UUID.', $value)
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
