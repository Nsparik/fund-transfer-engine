<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Raised when the source account does not hold enough funds to cover
 * the requested transfer amount.
 *
 * This exception lives in the Account bounded context because the balance
 * invariant is enforced by the Account aggregate root, not the Transfer.
 */
final class InsufficientFundsException extends AccountDomainException
{
    public function getDomainCode(): string
    {
        return 'INSUFFICIENT_FUNDS';
    }
}
