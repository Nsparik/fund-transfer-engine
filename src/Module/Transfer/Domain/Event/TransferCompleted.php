<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Event;

use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;

/**
 * Raised when a Transfer transitions from PROCESSING to COMPLETED.
 *
 * Published after both account balances have been atomically updated and
 * the transaction has committed.  Downstream consumers (audit log,
 * notification service, analytics) subscribe to this event to record
 * that funds were successfully moved.
 *
 * ## Self-contained design
 *   sourceAccountId and destinationAccountId are carried on the event so
 *   that consumers do not need a secondary query to determine which accounts
 *   were involved.  This is critical for future microservice extraction where
 *   the consumer may not have access to the Transfer read model.
 */
final readonly class TransferCompleted
{
    public function __construct(
        public readonly TransferId         $transferId,
        public readonly TransferReference  $reference,
        public readonly AccountId          $sourceAccountId,
        public readonly AccountId          $destinationAccountId,
        public readonly Money              $amount,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
