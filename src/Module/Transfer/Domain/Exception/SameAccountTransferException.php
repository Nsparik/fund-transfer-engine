<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Exception;

/**
 * Raised when the source and destination account IDs are identical.
 *
 * This is a distinct business-rule violation from an invalid amount —
 * it represents a self-transfer attempt which is never permitted
 * regardless of the amount.
 */
final class SameAccountTransferException extends TransferDomainException
{
    public function getDomainCode(): string
    {
        return 'SAME_ACCOUNT_TRANSFER';
    }
}
