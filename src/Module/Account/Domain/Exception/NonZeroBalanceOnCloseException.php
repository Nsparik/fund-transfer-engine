<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Raised when an attempt is made to close an account that still holds a
 * non-zero balance.
 *
 * ## Rationale
 *   Closing a funded account leaves funds in a terminal state with no owner —
 *   a financial data-integrity violation.  The balance MUST be transferred out
 *   (or explicitly zeroed by a compliance process) before the account can be
 *   closed.
 *
 * ## Recovery path
 *   The caller should first debit the remaining balance via a transfer, then
 *   retry the close operation.
 *
 * HTTP mapping: 409 Conflict — the current state of the resource (non-zero
 * balance) prevents the requested operation (close).
 */
final class NonZeroBalanceOnCloseException extends AccountDomainException
{
    public function getDomainCode(): string
    {
        return 'NON_ZERO_BALANCE_ON_CLOSE';
    }
}
