<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Transfer\Domain\ValueObject;

use App\Module\Transfer\Domain\ValueObject\TransferId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TransferIdTest extends TestCase
{
    // ── generate() ──────────────────────────────────────────────────────

    public function testGenerateProducesAValidUuid(): void
    {
        $id = TransferId::generate();

        self::assertTrue(Uuid::isValid($id->toString()));
    }

    public function testGenerateProducesUniqueValues(): void
    {
        $id1 = TransferId::generate();
        $id2 = TransferId::generate();

        self::assertFalse($id1->equals($id2));
    }

    // ── fromString() ─────────────────────────────────────────────────────

    public function testFromStringAcceptsWellFormedUuid(): void
    {
        $uuid = '019c86f1-d02b-7bf9-9c11-1176cbaf2f84';
        $id   = TransferId::fromString($uuid);

        self::assertSame($uuid, $id->toString());
    }

    public function testFromStringNormalisesUppercaseToLowercase(): void
    {
        $upper = '019C86F1-D02B-7BF9-9C11-1176CBAF2F84';
        $id    = TransferId::fromString($upper);

        self::assertSame(strtolower($upper), $id->toString());
    }

    public function testFromStringThrowsInvalidArgumentExceptionOnGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid TransferId');

        TransferId::fromString('not-a-uuid');
    }

    public function testFromStringThrowsInvalidArgumentExceptionOnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TransferId::fromString('');
    }

    public function testFromStringThrowsInvalidArgumentExceptionOnPartialUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TransferId::fromString('019c86f1-d02b-7bf9');
    }

    // ── equals() ────────────────────────────────────────────────────────

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $uuid = '019c86f1-d02b-7bf9-9c11-1176cbaf2f84';
        $id1  = TransferId::fromString($uuid);
        $id2  = TransferId::fromString($uuid);

        self::assertTrue($id1->equals($id2));
    }

    public function testEqualsReturnsTrueWhenOneIsUppercase(): void
    {
        $lower = TransferId::fromString('019c86f1-d02b-7bf9-9c11-1176cbaf2f84');
        $upper = TransferId::fromString('019C86F1-D02B-7BF9-9C11-1176CBAF2F84');

        self::assertTrue($lower->equals($upper));
    }

    public function testEqualsReturnsFalseForDifferentIds(): void
    {
        $id1 = TransferId::generate();
        $id2 = TransferId::generate();

        self::assertFalse($id1->equals($id2));
    }

    // ── toString / __toString ────────────────────────────────────────────

    public function testToStringMatchesExpectedValue(): void
    {
        $uuid = '019c86f1-d02b-7bf9-9c11-1176cbaf2f84';
        $id   = TransferId::fromString($uuid);

        self::assertSame($uuid, $id->toString());
        self::assertSame($uuid, $id->toString()); // explicit: no implicit __toString()
    }

    public function testToStringAlwaysReturnsLowercase(): void
    {
        $id = TransferId::fromString('019C86F1-D02B-7BF9-9C11-1176CBAF2F84');

        self::assertSame(strtolower('019C86F1-D02B-7BF9-9C11-1176CBAF2F84'), $id->toString());
    }
}
