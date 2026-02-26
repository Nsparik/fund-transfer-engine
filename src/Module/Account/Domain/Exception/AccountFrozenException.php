<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Raised when an operation is attempted on an account that has been frozen
 * (e.g. due to compliance, fraud investigation, or administrative action).
 *
 * Frozen accounts cannot be debited or credited until they are unfrozen
 * by an authorised operator.
 */
final class AccountFrozenException extends AccountDomainException
{
    public function getDomainCode(): string
    {
        return 'ACCOUNT_FROZEN';
    }
}
