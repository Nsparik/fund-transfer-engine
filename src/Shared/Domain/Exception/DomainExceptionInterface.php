<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marker interface for all domain exceptions across every bounded context.
 *
 * Both module-level base classes (TransferDomainException,
 * AccountDomainException) implement this interface so that the
 * DomainExceptionListener in the Shared UI layer can intercept any domain
 * exception with a single instanceof check — without needing to import a
 * concrete type from any specific module.
 *
 * ## Adding a new module
 *   1. Create `YourModuleDomainException extends \RuntimeException implements DomainExceptionInterface`
 *   2. All concrete exceptions in that module extend the base class.
 *   3. DomainExceptionListener automatically handles them — no modification needed.
 *
 * ## Contract
 *   Implementors must provide getDomainCode() that returns a SCREAMING_SNAKE_CASE
 *   string uniquely identifying the violated business rule.
 */
interface DomainExceptionInterface extends \Throwable
{
    /**
     * Machine-readable identifier for the violated business rule.
     *
     * Examples: "TRANSFER_NOT_FOUND", "INVALID_ACCOUNT_STATE".
     * Used by DomainExceptionListener to map to HTTP status codes without
     * importing concrete exception classes from any bounded context.
     */
    public function getDomainCode(): string;
}
