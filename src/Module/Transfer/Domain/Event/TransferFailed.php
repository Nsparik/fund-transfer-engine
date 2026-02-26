<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Event;

use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;

/**
 * Raised when a Transfer transitions from PROCESSING to FAILED.
 *
 * Published after the failed transfer record has been persisted.
 * Downstream consumers use failureCode to route notifications,
 * trigger compliance alerts, or trigger retry logic.
 *
 * ## Guarantee
 *   The accounts are guaranteed to be unchanged when this event fires —
 *   the debit/credit transaction was rolled back before this event is raised.
 *
 * ## Self-contained design
 *   sourceAccountId and destinationAccountId are carried on the event so
 *   consumers can act (notify, alert, audit) without a secondary query.
 *   This is critical for future microservice extraction.
 */
final readonly class TransferFailed
{
    public function __construct(
        public readonly TransferId         $transferId,
        public readonly TransferReference  $reference,
        public readonly AccountId          $sourceAccountId,
        public readonly AccountId          $destinationAccountId,
        public readonly Money              $amount,
        public readonly string             $failureCode,
        public readonly string             $failureReason,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
