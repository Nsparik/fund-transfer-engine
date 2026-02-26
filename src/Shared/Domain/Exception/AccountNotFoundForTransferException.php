<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Raised by AccountTransferPort when a referenced account does not exist.
 *
 * Wraps the Account module's AccountNotFoundException so the Transfer module
 * never imports Account domain types.
 *
 * Transfer handler behaviour on this exception:
 *   — Do NOT record a FAILED transfer (the input is simply invalid).
 *   — Propagate → DomainExceptionListener → HTTP 404.
 */
final class AccountNotFoundForTransferException extends \RuntimeException implements DomainExceptionInterface
{
    public function __construct(
        private readonly DomainExceptionInterface $cause,
    ) {
        parent::__construct($cause->getMessage(), 0, $cause);
    }

    public function getDomainCode(): string
    {
        return $this->cause->getDomainCode(); // ACCOUNT_NOT_FOUND → 404
    }
}
