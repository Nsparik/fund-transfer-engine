<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Ledger\Domain\ValueObject;

use App\Module\Ledger\Domain\ValueObject\LedgerEntryId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for LedgerEntryId value object.
 *
 * Covered:
 *   1. generate() emits a valid UUIDv7 string
 *   2. fromString() accepts any valid UUID without throwing
 *   3. Two generated IDs are monotonically ordered (UUIDv7 time-ordering guarantee)
 */
final class LedgerEntryIdTest extends TestCase
{
    public function testGenerateProducesValidUuidV7(): void
    {
        $id  = LedgerEntryId::generate();
        $str = $id->toString();

        // Must be a valid UUID in canonical form
        self::assertTrue(Uuid::isValid($str), "Generated value is not a valid UUID: {$str}");

        // Version nibble is the first character of the third group; must be '7'
        // e.g. "019c86f1-d02b-7bf9-..." — character at index 14 (0-based)
        self::assertSame('7', $str[14], "Expected UUIDv7 but got version nibble '{$str[14]}'");
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        // UUIDv4 fixture — fromString() must accept any valid UUID version
        $uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $id = LedgerEntryId::fromString($uuid);

        self::assertSame($uuid, $id->toString());
    }

    /**
     * UUIDv7 embeds a millisecond-precision Unix timestamp in its most-significant
     * bits, so lexicographic order == chronological order. Two IDs generated at
     * different times must satisfy strcmp(earlier, later) < 0.
     */
    public function testTwoGeneratedIdsAreMonotonicallyOrdered(): void
    {
        $id1 = LedgerEntryId::generate();

        // Sleep > 1 ms to guarantee a distinct millisecond timestamp
        usleep(1_100);

        $id2 = LedgerEntryId::generate();

        self::assertLessThan(
            0,
            strcmp($id1->toString(), $id2->toString()),
            'UUIDv7 IDs must be monotonically ordered: id1 should sort before id2',
        );
    }
}
