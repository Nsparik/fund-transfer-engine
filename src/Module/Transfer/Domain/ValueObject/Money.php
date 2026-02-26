<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\ValueObject;

/**
 * Represents a monetary amount in a specific currency.
 *
 * Amounts are stored in minor units (e.g. cents) as integers to avoid
 * floating-point precision issues.  A transfer of $10.50 USD is stored as
 * amountMinorUnits=1050, currency="USD".
 *
 * This class is immutable.  All arithmetic that produces a new value must
 * return a new Money instance.
 */
final class Money
{
    /**
     * @param int    $amountMinorUnits  Amount in the smallest currency unit (must be ≥ 0)
     * @param string $currency          ISO 4217 currency code, e.g. "USD", "EUR", "GBP"
     *
     * @throws \InvalidArgumentException on negative amount or non-3-char currency code
     */
    public function __construct(
        private readonly int $amountMinorUnits,
        private readonly string $currency,
    ) {
        if ($amountMinorUnits < 0) {
            throw new \InvalidArgumentException(
                'Money amount cannot be negative.'
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
     * @throws \LogicException when comparing Money of different currencies
     */
    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinorUnits > $other->amountMinorUnits;
    }

    /**
     * @throws \LogicException when comparing Money of different currencies
     */
    public function isGreaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinorUnits >= $other->amountMinorUnits;
    }

    public function isZero(): bool
    {
        return $this->amountMinorUnits === 0;
    }

    /**
     * Returns a human-readable decimal representation, e.g. "10.50 USD", "¥100 JPY", "3.150 KWD".
     * Not for financial calculations — display only.
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
                'Cannot compare Money amounts of different currencies: %s vs %s.',
                $this->currency,
                $other->currency
            ));
        }
    }
}
