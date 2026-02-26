<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Event;

use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferReference;
use App\Module\Transfer\Domain\ValueObject\TransferId;

/**
 * Raised when a new Transfer is successfully initiated.
 *
 * This is a plain PHP object — no framework dependencies.
 * Consumers (event listeners, outbox writers) receive it via
 * Transfer::releaseEvents() after the aggregate is persisted.
 */
final readonly class TransferInitiated
{
    public function __construct(
        public readonly TransferId        $transferId,
        public readonly TransferReference $reference,
        public readonly AccountId         $sourceAccountId,
        public readonly AccountId         $destinationAccountId,
        public readonly Money             $amount,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
