<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Exception;

/**
 * Raised when a Transfer aggregate cannot be found by its ID.
 *
 * Infrastructure repositories throw this from getById(); callers that
 * want a nullable result should use findById() instead.
 */
final class TransferNotFoundException extends TransferDomainException
{
    public function getDomainCode(): string
    {
        return 'TRANSFER_NOT_FOUND';
    }
}
