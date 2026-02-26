<?php

declare(strict_types=1);

namespace App\Module\Ledger\Domain\Exception;

/**
 * Thrown when a requested date range is invalid for a statement query.
 *
 * Conditions:
 *   - $from is after $to
 *   - The range spans more than 366 days (prevents runaway query cost)
 *   - A date string cannot be parsed as ISO 8601 UTC
 *
 * HTTP: 422 Unprocessable Entity (maps via DomainExceptionListener default).
 */
final class InvalidDateRangeException extends LedgerDomainException
{
    public function getDomainCode(): string
    {
        return 'INVALID_DATE_RANGE';
    }
}
