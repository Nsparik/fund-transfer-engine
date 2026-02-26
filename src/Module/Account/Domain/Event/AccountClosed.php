<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

use App\Module\Account\Domain\ValueObject\AccountId;

/**
 * Raised when an Account transitions from ACTIVE or FROZEN to CLOSED.
 *
 * CLOSED is a terminal state — the account can never be reopened,
 * debited, or credited after this event is emitted.
 */
final readonly class AccountClosed
{
    public function __construct(
        public readonly AccountId          $accountId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
