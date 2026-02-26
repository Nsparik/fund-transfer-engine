<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Raised when an operation is attempted on a permanently closed account.
 *
 * A CLOSED account is a terminal state — it can never be re-opened, debited,
 * or credited.  This exception is distinct from AccountFrozenException:
 * a frozen account can be unfrozen and used again; a closed account cannot.
 *
 * HTTP mapping: 409 Conflict (the current state of the resource prevents
 * the requested operation, and the state is permanent).
 */
final class AccountClosedException extends AccountDomainException
{
    public function getDomainCode(): string
    {
        return 'ACCOUNT_CLOSED';
    }
}
