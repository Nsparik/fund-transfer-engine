<?php

declare(strict_types=1);

namespace App\Module\Ledger\Domain\ValueObject;

/**
 * AccountId — parse-only value object for the Ledger bounded context.
 *
 * The Ledger module references accounts by ID but owns no Account aggregate.
 * This VO exists solely to provide type-safety at the domain boundary.
 * Generation (UUID v4) is the Account module's responsibility.
 *
 * Plain PHP — no framework dependencies.
 */
final readonly class AccountId
{
    private function __construct(
        private string $value,
    ) {}

    /**
     * Parse any valid UUID string.
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
                sprintf('Invalid Ledger AccountId: "%s" is not a valid UUID.', $value),
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
