<?php

declare(strict_types=1);

namespace App\Module\Ledger\Domain\Exception;

use App\Shared\Domain\Exception\DomainExceptionInterface;

/**
 * Base exception for all Ledger bounded-context domain exceptions.
 *
 * Implements DomainExceptionInterface so DomainExceptionListener can
 * map it to an HTTP status code via getDomainCode() without instanceof checks.
 *
 * All concrete Ledger exceptions extend this class.
 */
abstract class LedgerDomainException extends \RuntimeException implements DomainExceptionInterface
{
}
