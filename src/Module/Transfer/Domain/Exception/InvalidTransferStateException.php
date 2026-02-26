<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Exception;

/**
 * Raised when an illegal state-machine transition is attempted on a Transfer.
 */
final class InvalidTransferStateException extends TransferDomainException
{
    public function getDomainCode(): string
    {
        return 'INVALID_TRANSFER_STATE';
    }
}
