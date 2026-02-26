<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Transfer\Domain\ValueObject;

use App\Module\Transfer\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    // ── Construction ────────────────────────────────────────────────────

    public function testValidConstructionStoresValues(): void
    {
        $money = new Money(1050, 'USD');

        self::assertSame(1050, $money->getAmountMinorUnits());
        self::assertSame('USD', $money->getCurrency());
    }

    public function testZeroAmountIsAllowed(): void
    {
        $money = new Money(0, 'EUR');

        self::assertTrue($money->isZero());
    }

    public function testNegativeAmountThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');

        new Money(-1, 'USD');
    }

    public function testLowercaseCurrencyThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('3-character uppercase');

        new Money(100, 'usd');
    }

    public function testTwoCharCurrencyThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('3-character uppercase');

        new Money(100, 'US');
    }

    public function testFourCharCurrencyThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('3-character uppercase');

        new Money(100, 'USDT');
    }

    // ── isZero ──────────────────────────────────────────────────────────

    public function testIsZeroReturnsTrueForZeroAmount(): void
    {
        self::assertTrue((new Money(0, 'GBP'))->isZero());
    }

    public function testIsZeroReturnsFalseForPositiveAmount(): void
    {
        self::assertFalse((new Money(1, 'GBP'))->isZero());
    }

    // ── isGreaterThan ────────────────────────────────────────────────────

    public function testIsGreaterThanReturnsTrueWhenBigger(): void
    {
        self::assertTrue((new Money(200, 'USD'))->isGreaterThan(new Money(100, 'USD')));
    }

    public function testIsGreaterThanReturnsFalseWhenSmaller(): void
    {
        self::assertFalse((new Money(100, 'USD'))->isGreaterThan(new Money(200, 'USD')));
    }

    public function testIsGreaterThanReturnsFalseForEqualAmounts(): void
    {
        self::assertFalse((new Money(100, 'USD'))->isGreaterThan(new Money(100, 'USD')));
    }

    public function testIsGreaterThanThrowsLogicExceptionOnCurrencyMismatch(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('different currencies');

        (new Money(100, 'USD'))->isGreaterThan(new Money(100, 'EUR'));
    }

    // ── isGreaterThanOrEqual ─────────────────────────────────────────────

    public function testIsGreaterThanOrEqualReturnsTrueForEqualAmounts(): void
    {
        self::assertTrue((new Money(100, 'USD'))->isGreaterThanOrEqual(new Money(100, 'USD')));
    }

    // ── equals ──────────────────────────────────────────────────────────

    public function testEqualsReturnsTrueForIdenticalMoney(): void
    {
        $a = new Money(500, 'GBP');
        $b = new Money(500, 'GBP');

        self::assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenAmountsDiffer(): void
    {
        $a = new Money(500, 'GBP');
        $b = new Money(501, 'GBP');

        self::assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenCurrenciesDiffer(): void
    {
        $a = new Money(500, 'GBP');
        $b = new Money(500, 'USD');

        self::assertFalse($a->equals($b));
    }

    // ── isSameCurrency ───────────────────────────────────────────────────

    public function testIsSameCurrencyReturnsTrueForMatchingCurrencies(): void
    {
        self::assertTrue((new Money(100, 'USD'))->isSameCurrency(new Money(200, 'USD')));
    }

    public function testIsSameCurrencyReturnsFalseForDifferentCurrencies(): void
    {
        self::assertFalse((new Money(100, 'USD'))->isSameCurrency(new Money(100, 'EUR')));
    }

    // ── __toString decimal-aware formatting ──────────────────────────────────

    public function testToStringTwoDecimalCurrencyUSD(): void
    {
        self::assertSame('10.50 USD', (string) new Money(1050, 'USD'));
    }

    public function testToStringTwoDecimalCurrencyGBP(): void
    {
        // 1 minor unit of GBP = £0.01
        self::assertSame('0.01 GBP', (string) new Money(1, 'GBP'));
    }

    public function testToStringZeroDecimalCurrencyJPY(): void
    {
        // JPY has no subunit — 500 minor units = ¥500
        self::assertSame('500 JPY', (string) new Money(500, 'JPY'));
    }

    public function testToStringZeroDecimalCurrencyKRW(): void
    {
        // KRW has no subunit — 10000 minor units = ₩10000
        self::assertSame('10000 KRW', (string) new Money(10000, 'KRW'));
    }

    public function testToStringThreeDecimalCurrencyKWD(): void
    {
        // KWD uses 3 decimal places — 3150 minor units = 3.150 KWD
        self::assertSame('3.150 KWD', (string) new Money(3150, 'KWD'));
    }

    public function testToStringZeroAmountUSD(): void
    {
        self::assertSame('0.00 USD', (string) new Money(0, 'USD'));
    }
}
