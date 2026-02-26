<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Raised when a transfer is attempted between accounts denominated in
 * different currencies without an explicit FX conversion.
 *
 * The system only permits transfers where source and destination accounts
 * share the same currency.
 */
final class CurrencyMismatchException extends AccountDomainException
{
    public function getDomainCode(): string
    {
        return 'CURRENCY_MISMATCH';
    }
}
