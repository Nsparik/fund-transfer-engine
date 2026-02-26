<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * Unique identifier for an Account aggregate.
 *
 * Structurally identical to the Transfer module's AccountId but intentionally
 * a separate type so that the Account bounded context owns its own identity
 * concept without coupling to the Transfer module.
 *
 * The transfer handler looks up accounts by converting both module AccountId
 * types via string, keeping the modules independent.
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
