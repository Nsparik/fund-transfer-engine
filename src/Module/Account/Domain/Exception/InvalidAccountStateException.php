<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Raised when a state transition is requested on an Account that is not
 * permitted by the current lifecycle status.
 *
 * Examples:
 *   - Calling freeze() on a FROZEN account
 *   - Calling freeze() on a CLOSED account
 *   - Calling unfreeze() on an ACTIVE account
 *   - Calling unfreeze() on a CLOSED account
 *
 * Maps to HTTP 409 Conflict — the request is valid but contradicts the
 * current resource state.
 */
final class InvalidAccountStateException extends AccountDomainException
{
    public function getDomainCode(): string
    {
        return 'INVALID_ACCOUNT_STATE';
    }
}
