<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Exception;

/**
 * Raised when a transfer amount violates business rules.
 *
 * Currently covers:
 *   - Zero or negative amount (Transfer::initiate enforces amount > 0)
 *
 * Note: same-account transfers use SameAccountTransferException.
 * Currency mismatch is handled by a dedicated guard in the Account domain.
 */
final class InvalidTransferAmountException extends TransferDomainException
{
    public function getDomainCode(): string
    {
        return 'INVALID_TRANSFER_AMOUNT';
    }
}
