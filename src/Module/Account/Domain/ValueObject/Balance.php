<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\ValueObject;

use App\Module\Account\Domain\Exception\BalanceOverflowException;
use App\Module\Account\Domain\Exception\InsufficientFundsException;

/**
 * Monetary balance held by an Account.
 *
 * Stored as integer minor-units (e.g. cents) to avoid floating-point
 * precision issues.  The currency is embedded in the Balance so that
 * operations across currencies are detected and rejected at the value
 * object level.
 *
 * Immutable — all arithmetic returns a new Balance instance.
 */
final class Balance
{
    /**
     * @param int    $amountMinorUnits  Must be ≥ 0
     * @param string $currency          3-char uppercase ISO 4217 code
     *
     * @throws \InvalidArgumentException on negative amount or invalid currency
     */
    public function __construct(
        private readonly int    $amountMinorUnits,
        private readonly string $currency,
    ) {
        if ($amountMinorUnits < 0) {
            throw new \InvalidArgumentException(
                'Balance amount cannot be negative.'
            );
        }

        if (strlen($currency) !== 3 || strtoupper($currency) !== $currency) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Currency must be a 3-character uppercase ISO 4217 code, got "%s".',
                    $currency
                )
            );
        }
    }

    public function getAmountMinorUnits(): int
    {
        return $this->amountMinorUnits;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Return a new Balance increased by $amount.
     *
     * @throws \LogicException          on currency mismatch
     * @throws BalanceOverflowException  when the resulting balance would exceed PHP_INT_MAX
     *                                   (prevents silent float-promotion on 64-bit PHP with strict_types)
     */
    public function add(self $amount): self
    {
        $this->assertSameCurrency($amount);

        // Guard against integer overflow: on strict_types=1 PHP promotes int+int→float
        // when the result exceeds PHP_INT_MAX, which then fails the constructor's
        // int type hint with a TypeError (uncaught 500) instead of a domain exception.
        // PHP_INT_MAX on 64-bit = 9_223_372_036_854_775_807 ≈ 92 quadrillion minor units
        // — far beyond any real-world balance, but we guard explicitly for correctness.
        if ($amount->amountMinorUnits > PHP_INT_MAX - $this->amountMinorUnits) {
            throw new BalanceOverflowException(sprintf(
                'Balance addition would overflow: %d + %d exceeds PHP_INT_MAX (%d).',
                $this->amountMinorUnits,
                $amount->amountMinorUnits,
                PHP_INT_MAX,
            ));
        }

        return new self($this->amountMinorUnits + $amount->amountMinorUnits, $this->currency);
    }

    /**
     * Return a new Balance decreased by $amount.
     *
     * @throws InsufficientFundsException when the result would be negative
     * @throws \LogicException            on currency mismatch
     */
    public function subtract(self $amount): self
    {
        $this->assertSameCurrency($amount);

        if ($amount->amountMinorUnits > $this->amountMinorUnits) {
            throw new InsufficientFundsException(sprintf(
                'Insufficient funds: balance is %d %s, requested debit is %d %s.',
                $this->amountMinorUnits,
                $this->currency,
                $amount->amountMinorUnits,
                $amount->currency,
            ));
        }

        return new self($this->amountMinorUnits - $amount->amountMinorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amountMinorUnits === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amountMinorUnits === $other->amountMinorUnits
            && $this->currency === $other->currency;
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    /**
     * Display-only string, e.g. "10.50 USD", "¥100 JPY", "3.150 KWD".
     * Not for financial calculations.
     */
    public function __toString(): string
    {
        $decimals = match ($this->currency) {
            'JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF', 'GNF', 'PYG', 'RWF', 'UGX' => 0,
            'KWD', 'BHD', 'OMR', 'JOD', 'IQD', 'LYD', 'TND'                        => 3,
            default                                                                   => 2,
        };

        return sprintf(
            '%.' . $decimals . 'f %s',
            $this->amountMinorUnits / (10 ** $decimals),
            $this->currency,
        );
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->isSameCurrency($other)) {
            throw new \LogicException(sprintf(
                'Cannot operate on Balance amounts of different currencies: %s vs %s.',
                $this->currency,
                $other->currency,
            ));
        }
    }
}
