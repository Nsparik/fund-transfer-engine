<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Account\Domain\ValueObject;

use App\Module\Account\Domain\Exception\BalanceOverflowException;
use App\Module\Account\Domain\Exception\InsufficientFundsException;
use App\Module\Account\Domain\ValueObject\Balance;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Balance value object.
 */
final class BalanceTest extends TestCase
{
    // ── Construction ────────────────────────────────────────────────────────

    public function testValidConstructionStoresValues(): void
    {
        $b = new Balance(1050, 'USD');

        self::assertSame(1050, $b->getAmountMinorUnits());
        self::assertSame('USD', $b->getCurrency());
    }

    public function testZeroAmountIsAllowed(): void
    {
        $b = new Balance(0, 'EUR');

        self::assertTrue($b->isZero());
    }

    public function testNegativeAmountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');

        new Balance(-1, 'USD');
    }

    public function testLowercaseCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('3-character uppercase');

        new Balance(100, 'usd');
    }

    public function testFourCharCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Balance(100, 'USDT');
    }

    // ── add() ────────────────────────────────────────────────────────────────

    public function testAddReturnsNewBalanceWithSummedAmount(): void
    {
        $result = (new Balance(1000, 'USD'))->add(new Balance(500, 'USD'));

        self::assertSame(1500, $result->getAmountMinorUnits());
        self::assertSame('USD', $result->getCurrency());
    }

    public function testAddIsImmutable(): void
    {
        $original = new Balance(1000, 'USD');
        $original->add(new Balance(500, 'USD'));

        self::assertSame(1000, $original->getAmountMinorUnits());
    }

    public function testAddThrowsOnCurrencyMismatch(): void
    {
        $this->expectException(\LogicException::class);

        (new Balance(1000, 'USD'))->add(new Balance(500, 'EUR'));
    }

    public function testAddThrowsOverflowExceptionWhenResultExceedsPhpIntMax(): void
    {
        $this->expectException(BalanceOverflowException::class);
        $this->expectExceptionMessageMatches('/overflow/i');

        // PHP_INT_MAX + 1 would silently become a float on 64-bit without the guard.
        $max   = new Balance(PHP_INT_MAX, 'USD');
        $one   = new Balance(1, 'USD');
        $max->add($one);
    }

    public function testAddAtPhpIntMaxBoundaryDoesNotOverflow(): void
    {
        // PHP_INT_MAX itself added to zero must succeed.
        $max    = new Balance(PHP_INT_MAX, 'USD');
        $zero   = new Balance(0, 'USD');
        $result = $max->add($zero);

        self::assertSame(PHP_INT_MAX, $result->getAmountMinorUnits());
    }

    // ── subtract() ───────────────────────────────────────────────────────────

    public function testSubtractReturnsNewBalanceWithDifference(): void
    {
        $result = (new Balance(1000, 'USD'))->subtract(new Balance(300, 'USD'));

        self::assertSame(700, $result->getAmountMinorUnits());
    }

    public function testSubtractToZeroIsAllowed(): void
    {
        $result = (new Balance(500, 'USD'))->subtract(new Balance(500, 'USD'));

        self::assertTrue($result->isZero());
    }

    public function testSubtractIsImmutable(): void
    {
        $original = new Balance(1000, 'USD');
        $original->subtract(new Balance(300, 'USD'));

        self::assertSame(1000, $original->getAmountMinorUnits());
    }

    public function testSubtractThrowsInsufficientFundsWhenAmountExceedsBalance(): void
    {
        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessage('Insufficient funds');

        (new Balance(100, 'USD'))->subtract(new Balance(200, 'USD'));
    }

    public function testSubtractThrowsOnCurrencyMismatch(): void
    {
        $this->expectException(\LogicException::class);

        (new Balance(1000, 'USD'))->subtract(new Balance(500, 'EUR'));
    }

    // ── equals / isSameCurrency ──────────────────────────────────────────────

    public function testEqualsReturnsTrueForIdenticalValues(): void
    {
        self::assertTrue((new Balance(100, 'USD'))->equals(new Balance(100, 'USD')));
    }

    public function testEqualsReturnsFalseForDifferentAmount(): void
    {
        self::assertFalse((new Balance(100, 'USD'))->equals(new Balance(200, 'USD')));
    }

    public function testEqualsReturnsFalseForDifferentCurrency(): void
    {
        self::assertFalse((new Balance(100, 'USD'))->equals(new Balance(100, 'EUR')));
    }

    public function testIsSameCurrencyReturnsTrueForMatchingCurrencies(): void
    {
        self::assertTrue((new Balance(100, 'USD'))->isSameCurrency(new Balance(999, 'USD')));
    }

    public function testIsSameCurrencyReturnsFalseForDifferentCurrencies(): void
    {
        self::assertFalse((new Balance(100, 'USD'))->isSameCurrency(new Balance(100, 'GBP')));
    }

    // ── __toString decimal-aware formatting ──────────────────────────────────

    public function testToStringTwoDecimalCurrencyUSD(): void
    {
        self::assertSame('10.50 USD', (string) new Balance(1050, 'USD'));
    }

    public function testToStringTwoDecimalCurrencyEUR(): void
    {
        self::assertSame('0.01 EUR', (string) new Balance(1, 'EUR'));
    }

    public function testToStringZeroDecimalCurrencyJPY(): void
    {
        // 100 minor units of JPY = ¥100 (JPY has no subunit)
        self::assertSame('100 JPY', (string) new Balance(100, 'JPY'));
    }

    public function testToStringZeroDecimalCurrencyKRW(): void
    {
        self::assertSame('5000 KRW', (string) new Balance(5000, 'KRW'));
    }

    public function testToStringThreeDecimalCurrencyKWD(): void
    {
        // 1500 minor units of KWD = 1.500 KWD
        self::assertSame('1.500 KWD', (string) new Balance(1500, 'KWD'));
    }

    public function testToStringZeroBalanceUSD(): void
    {
        self::assertSame('0.00 USD', (string) new Balance(0, 'USD'));
    }
}
