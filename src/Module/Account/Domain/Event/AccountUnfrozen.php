<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

use App\Module\Account\Domain\ValueObject\AccountId;

/**
 * Raised when an Account transitions from FROZEN back to ACTIVE.
 *
 * Published after the unfreeze() command succeeds and the transaction commits.
 * Consumers (audit log, compliance, notification service) subscribe to this
 * event to record that an account has been reinstated for normal operation.
 */
final readonly class AccountUnfrozen
{
    public function __construct(
        public readonly AccountId          $accountId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
