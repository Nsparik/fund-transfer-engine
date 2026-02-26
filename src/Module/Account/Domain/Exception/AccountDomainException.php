<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

use App\Shared\Domain\Exception\DomainExceptionInterface;

/**
 * Base class for all domain exceptions in the Account bounded context.
 *
 * Every concrete subclass must declare a machine-readable domain code that
 * uniquely identifies the business-rule violation.  DomainExceptionListener
 * maps these codes to HTTP status codes without importing concrete types.
 */
abstract class AccountDomainException extends \RuntimeException implements DomainExceptionInterface
{
    abstract public function getDomainCode(): string;
}
