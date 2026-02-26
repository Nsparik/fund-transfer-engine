<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Raised when a Balance::add() operation would overflow PHP_INT_MAX.
 *
 * PHP_INT_MAX on 64-bit = 9_223_372_036_854_775_807 ≈ 92 quadrillion minor
 * units — far beyond any real-world monetary balance.  This exception exists
 * purely for correctness: without it, a pathological credit would silently
 * promote the sum to a float (via PHP_INT_MAX + 1 → float), which would then
 * fail the constructor's int type hint with a TypeError (HTTP 500) rather than
 * a structured domain error.
 *
 * Extending AccountDomainException ensures DomainExceptionListener maps this
 * to HTTP 422 with a machine-readable BALANCE_OVERFLOW code, giving the caller
 * a clear, actionable error instead of a generic server fault.
 */
final class BalanceOverflowException extends AccountDomainException
{
    public function getDomainCode(): string
    {
        return 'BALANCE_OVERFLOW';
    }
}
