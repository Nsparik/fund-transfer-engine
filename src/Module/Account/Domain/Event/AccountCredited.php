<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Domain\ValueObject\Balance;

/**
 * Raised when funds are successfully credited to an Account.
 *
 * Consumers (ledger writer, audit log, notifications) listen to this event
 * to record the credit leg of a double-entry transfer.
 *
 * ## transferType field
 *   'transfer'  — funds arriving as the destination of an original transfer
 *   'reversal'  — funds returned to a source account during a reversal
 *
 * ## counterpartyAccountId
 *   The other account in the transfer. Stored on the event so downstream
 *   consumers (ledger, statements) can show "sent to / received from account X"
 *   without a secondary query against the transfers table.
 *
 * Plain PHP object — no framework dependencies.
 */
final readonly class AccountCredited
{
    public function __construct(
        public readonly AccountId          $accountId,
        public readonly Balance            $amount,
        public readonly Balance            $balanceAfter,
        public readonly string             $transferId,
        public readonly string             $transferType,
        public readonly string             $counterpartyAccountId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
