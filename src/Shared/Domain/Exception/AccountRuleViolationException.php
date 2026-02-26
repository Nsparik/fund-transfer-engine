<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Raised by AccountTransferPort when a business rule blocks the transfer.
 *
 * Wraps AccountDomainException subtypes:
 *   AccountFrozenException     → ACCOUNT_FROZEN     → HTTP 409
 *   AccountClosedException     → ACCOUNT_CLOSED     → HTTP 409
 *   InsufficientFundsException → INSUFFICIENT_FUNDS → HTTP 422
 *   CurrencyMismatchException  → CURRENCY_MISMATCH  → HTTP 422
 *
 * Transfer handler behaviour on this exception:
 *   — Record a FAILED transfer with the wrapped domain code.
 *   — Propagate → DomainExceptionListener → HTTP 409 or 422.
 */
final class AccountRuleViolationException extends \RuntimeException implements DomainExceptionInterface
{
    public function __construct(
        private readonly DomainExceptionInterface $cause,
    ) {
        parent::__construct($cause->getMessage(), 0, $cause);
    }

    public function getDomainCode(): string
    {
        return $this->cause->getDomainCode();
    }
}
