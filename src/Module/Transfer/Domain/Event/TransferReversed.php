<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Event;

use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferReference;
use App\Module\Transfer\Domain\ValueObject\TransferId;

/**
 * Raised when a completed Transfer is reversed.
 *
 * Consumers (ledger reconciliation, notifications, fraud detection) listen
 * to this event to credit back the source account and debit the destination.
 *
 * ## Self-contained design
 *   sourceAccountId and destinationAccountId are carried on the event so
 *   that consumers can reverse the double-entry ledger entries (credit source,
 *   debit destination) without a secondary query.  This is critical for future
 *   microservice extraction where the consumer may not have access to the
 *   Transfer read model.
 *
 * This is a plain PHP object — no framework dependencies.
 */
final readonly class TransferReversed
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
