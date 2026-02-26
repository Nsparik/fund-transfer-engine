<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Exception;

use App\Shared\Domain\Exception\DomainExceptionInterface;

/**
 * Base class for all domain exceptions in the Transfer module.
 *
 * Every concrete subclass must declare a machine-readable domain code that
 * uniquely identifies the business-rule violation.  The code is used by
 * DomainExceptionListener to map exceptions to HTTP responses without
 * importing concrete exception types from any specific module.
 *
 * Application-layer catch blocks should catch this base type to handle any
 * business-rule violation without depending on concrete exception classes.
 */
abstract class TransferDomainException extends \RuntimeException implements DomainExceptionInterface
{
    /**
     * Machine-readable identifier for this domain error.
     * Examples: "TRANSFER_NOT_FOUND", "INVALID_TRANSFER_STATE".
     *
     * This is a domain concept — it identifies the business rule that was
     * violated.  HTTP status code mapping is the concern of the UI layer.
     */
    abstract public function getDomainCode(): string;
}
