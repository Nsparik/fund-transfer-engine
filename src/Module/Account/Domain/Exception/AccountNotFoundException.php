<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Raised when an Account cannot be found by its ID.
 *
 * Infrastructure repositories throw this from getById();
 * callers that want a nullable result should use findById() instead.
 */
final class AccountNotFoundException extends AccountDomainException
{
    public function getDomainCode(): string
    {
        return 'ACCOUNT_NOT_FOUND';
    }
}
