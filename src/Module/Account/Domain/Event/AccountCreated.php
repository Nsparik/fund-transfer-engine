<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Domain\ValueObject\Balance;

/**
 * Raised when a new Account is successfully opened.
 *
 * Plain PHP object — no framework dependencies.
 */
final readonly class AccountCreated
{
    public function __construct(
        public readonly AccountId          $accountId,
        public readonly string             $ownerName,
        public readonly Balance            $initialBalance,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
