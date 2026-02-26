<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

use App\Module\Account\Domain\ValueObject\AccountId;

/**
 * Raised when an Account transitions from ACTIVE to FROZEN.
 *
 * Published after the freeze() command succeeds and the transaction commits.
 * Consumers (audit log, compliance alerting, notification service) subscribe
 * to this event to record who froze the account and when.
 *
 * Note: the aggregate does not capture the operator identity — that concern
 * belongs to the Application layer (command metadata / authentication context).
 */
final readonly class AccountFrozen
{
    public function __construct(
        public readonly AccountId          $accountId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
