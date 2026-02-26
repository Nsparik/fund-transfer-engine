<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Ledger\Infrastructure\Persistence;

use App\Module\Ledger\Domain\Model\LedgerEntry;
use App\Module\Ledger\Domain\ValueObject\AccountId;
use App\Module\Ledger\Domain\ValueObject\EntryType;
use App\Module\Ledger\Domain\ValueObject\LedgerEntryId;
use App\Module\Ledger\Infrastructure\Persistence\DbalLedgerRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DbalLedgerRepository against the real MySQL container.
 *
 * Builds a raw DBAL Connection from DATABASE_URL (populated by bootEnv() in
 * bootstrap.php) — no Symfony kernel required.
 * Each test tracks its own inserted row IDs and cleans them up in tearDown().
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class DbalLedgerRepositoryTest extends TestCase
{
    private DbalLedgerRepository $repository;
    private Connection           $connection;

    /** @var list<string> LedgerEntry IDs to DELETE in tearDown */
    private array $insertedIds = [];

    /**
     * Every account UUID used as `account_id` in this test class.
     *
     * The FK constraint fk_ledger_entries_account (account_id → accounts.id,
     * added in Version20260226000001) requires real rows in the accounts table
     * before any INSERT INTO ledger_entries can succeed.
     *
     * Only the `account_id` column is FK-constrained; `counterparty_account_id`
     * has no FK and may hold synthetic UUIDs without a matching accounts row.
     */
    private const FIXTURE_ACCOUNT_IDS = [
        '11111111-1111-4111-a111-111111111111', // testSaveDebitEntryPersistsAllFields
        '33333333-3333-4333-a333-333333333333', // testSaveCreditEntryPersistsAllFields
        '55555555-5555-4555-a555-555555555555', // testFindByAccountAndDateRangeReturnsEntriesInRange
        '77777777-7777-4777-a777-777777777777', // testFindByAccountAndDateRangeExcludesEntriesOutsideRange
        'aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa', // testFindByAccountAndDateRangePaginatesCorrectly
        'cccccccc-aaaa-4ccc-accc-cccccccccccc', // testFindLastEntryBeforeReturnsMostRecentEntryBeforeTimestamp
        'eeeeeeee-cccc-4eee-aeee-eeeeeeeeeeee', // testFindLastEntryForAccountReturnsMostRecentOverall
        'aaaaaaaa-eeee-4aaa-8aaa-aaaaaaaaaaaa', // testUniqueConstraintPreventsDuplicateDebitForSameTransfer
        'dddddddd-eeee-4ddd-addd-dddddddddddd', // testUniqueConstraintAllowsDebitAndCreditForSameTransfer (debit side)
        'eeeeeeee-ffff-4eee-aeee-eeeeeeeeeeee', // testUniqueConstraintAllowsDebitAndCreditForSameTransfer (credit side)
    ];

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection = DriverManager::getConnection(['url' => $url]);
        $this->repository = new DbalLedgerRepository($this->connection);

        // Pre-create fixture accounts to satisfy fk_ledger_entries_account.
        // INSERT IGNORE is safe: if a previous tearDown somehow missed cleanup
        // the row already exists and we proceed without error.
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach (self::FIXTURE_ACCOUNT_IDS as $id) {
            $this->connection->executeStatement(
                "INSERT IGNORE INTO accounts
                    (id, owner_name, currency, balance_minor_units, status, version, created_at, updated_at)
                 VALUES (?, 'fixture', 'USD', 0, 'active', 0, ?, ?)",
                [$id, $now, $now],
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->insertedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->insertedIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM ledger_entries WHERE id IN ({$placeholders})",
                $this->insertedIds,
            );
            $this->insertedIds = [];
        }

        // Delete fixture accounts AFTER ledger_entries (FK order)
        $placeholders = implode(',', array_fill(0, count(self::FIXTURE_ACCOUNT_IDS), '?'));
        $this->connection->executeStatement(
            "DELETE FROM accounts WHERE id IN ({$placeholders})",
            self::FIXTURE_ACCOUNT_IDS,
        );
    }

    // ── save(): debit ─────────────────────────────────────────────────────────

    public function testSaveDebitEntryPersistsAllFields(): void
    {
        $accountId     = AccountId::fromString('11111111-1111-4111-a111-111111111111');
        $counterparty  = AccountId::fromString('22222222-2222-4222-a222-222222222222');
        $transferId    = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $occurredAt    = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        $entry = LedgerEntry::recordDebit(
            accountId:              $accountId,
            counterpartyAccountId:  $counterparty,
            transferId:             $transferId,
            transferType:           'transfer',
            amountMinorUnits:       1_500,
            currency:               'USD',
            balanceAfterMinorUnits: 8_500,
            occurredAt:             $occurredAt,
        );
        $this->repository->save($entry);
        $this->track($entry);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ledger_entries WHERE id = ?',
            [$entry->getId()->toString()],
        );

        self::assertIsArray($row);
        self::assertSame($entry->getId()->toString(), $row['id']);
        self::assertSame($accountId->toString(),    $row['account_id']);
        self::assertSame($counterparty->toString(), $row['counterparty_account_id']);
        self::assertSame($transferId,               $row['transfer_id']);
        self::assertSame(EntryType::DEBIT->value,   $row['entry_type']);
        self::assertSame('transfer',                $row['transfer_type']);
        self::assertSame(1_500,   (int) $row['amount_minor_units']);
        self::assertSame('USD',       $row['currency']);
        self::assertSame(8_500,   (int) $row['balance_after_minor_units']);
        self::assertStringStartsWith('2026-01-15', $row['occurred_at']);
    }

    // ── save(): credit ────────────────────────────────────────────────────────

    public function testSaveCreditEntryPersistsAllFields(): void
    {
        $accountId    = AccountId::fromString('33333333-3333-4333-a333-333333333333');
        $counterparty = AccountId::fromString('44444444-4444-4444-a444-444444444444');
        $transferId   = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $occurredAt   = new \DateTimeImmutable('2026-02-20 14:30:00', new \DateTimeZone('UTC'));

        $entry = LedgerEntry::recordCredit(
            accountId:              $accountId,
            counterpartyAccountId:  $counterparty,
            transferId:             $transferId,
            transferType:           'transfer',
            amountMinorUnits:       3_000,
            currency:               'EUR',
            balanceAfterMinorUnits: 3_000,
            occurredAt:             $occurredAt,
        );
        $this->repository->save($entry);
        $this->track($entry);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ledger_entries WHERE id = ?',
            [$entry->getId()->toString()],
        );

        self::assertIsArray($row);
        self::assertSame(EntryType::CREDIT->value, $row['entry_type']);
        self::assertSame('transfer',               $row['transfer_type']);
        self::assertSame(3_000, (int) $row['amount_minor_units']);
        self::assertSame('EUR',     $row['currency']);
        self::assertSame(3_000, (int) $row['balance_after_minor_units']);
    }

    // ── findByAccountIdAndDateRange() ─────────────────────────────────────────

    public function testFindByAccountAndDateRangeReturnsEntriesInRange(): void
    {
        $accountId    = AccountId::fromString('55555555-5555-4555-a555-555555555555');
        $counterparty = AccountId::fromString('66666666-6666-4666-a666-666666666666');

        $before = $this->makeDebit($accountId, $counterparty, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')), 100, 9_900);
        $inside = $this->makeDebit($accountId, $counterparty, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            new \DateTimeImmutable('2026-02-01 12:00:00', new \DateTimeZone('UTC')), 200, 9_700);
        $after  = $this->makeDebit($accountId, $counterparty, 'eeeeeeee-eeee-4eee-aeee-eeeeeeeeeeee',
            new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('UTC')), 300, 9_400);

        $from = new \DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('UTC'));
        $to   = new \DateTimeImmutable('2026-02-28 23:59:59', new \DateTimeZone('UTC'));

        $page = $this->repository->findByAccountIdAndDateRange($accountId, $from, $to, 1, 50);

        self::assertSame(1, $page->total);
        self::assertSame($inside->getId()->toString(), $page->entries[0]->getId()->toString());
    }

    public function testFindByAccountAndDateRangeExcludesEntriesOutsideRange(): void
    {
        $accountId    = AccountId::fromString('77777777-7777-4777-a777-777777777777');
        $counterparty = AccountId::fromString('88888888-8888-4888-a888-888888888888');

        // Both entries fall outside the query range
        $this->makeDebit($accountId, $counterparty, 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            new \DateTimeImmutable('2025-12-01 00:00:00', new \DateTimeZone('UTC')), 500, 9_500);
        $this->makeDebit($accountId, $counterparty, '11111111-2222-4333-a444-555555555555',
            new \DateTimeImmutable('2027-01-01 00:00:00', new \DateTimeZone('UTC')), 500, 9_000);

        $from = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $to   = new \DateTimeImmutable('2026-12-31 23:59:59', new \DateTimeZone('UTC'));

        $page = $this->repository->findByAccountIdAndDateRange($accountId, $from, $to, 1, 50);

        self::assertSame(0, $page->total);
        self::assertSame([], $page->entries);
    }

    public function testFindByAccountAndDateRangePaginatesCorrectly(): void
    {
        $accountId    = AccountId::fromString('aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa');
        $counterparty = AccountId::fromString('bbbbbbbb-2222-4bbb-8bbb-bbbbbbbbbbbb');

        $from = new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('UTC'));
        $to   = new \DateTimeImmutable('2026-03-31 23:59:59', new \DateTimeZone('UTC'));

        $this->makeDebit($accountId, $counterparty, 'cccccccc-1111-4ccc-8ccc-cccccccccccc',
            new \DateTimeImmutable('2026-03-05 10:00:00', new \DateTimeZone('UTC')), 100, 9_900);
        $this->makeDebit($accountId, $counterparty, 'dddddddd-1111-4ddd-8ddd-dddddddddddd',
            new \DateTimeImmutable('2026-03-10 10:00:00', new \DateTimeZone('UTC')), 200, 9_700);
        $this->makeDebit($accountId, $counterparty, 'eeeeeeee-1111-4eee-aeee-eeeeeeeeeeee',
            new \DateTimeImmutable('2026-03-15 10:00:00', new \DateTimeZone('UTC')), 300, 9_400);

        // Page 1: 2 items
        $page1 = $this->repository->findByAccountIdAndDateRange($accountId, $from, $to, 1, 2);

        self::assertSame(3,  $page1->total);
        self::assertCount(2,  $page1->entries);
        self::assertSame(2,   $page1->getTotalPages());

        // Page 2: 1 remaining item
        $page2 = $this->repository->findByAccountIdAndDateRange($accountId, $from, $to, 2, 2);

        self::assertSame(3,  $page2->total);
        self::assertCount(1, $page2->entries);
    }

    // ── findLastEntryBefore() ─────────────────────────────────────────────────

    public function testFindLastEntryBeforeReturnsMostRecentEntryBeforeTimestamp(): void
    {
        $accountId    = AccountId::fromString('cccccccc-aaaa-4ccc-accc-cccccccccccc');
        $counterparty = AccountId::fromString('dddddddd-bbbb-4ddd-addd-dddddddddddd');

        $earlier = $this->makeDebit($accountId, $counterparty, 'aaaaaaaa-cccc-4aaa-8aaa-aaaaaaaaaaaa',
            new \DateTimeImmutable('2026-04-01 08:00:00', new \DateTimeZone('UTC')), 100, 9_900);
        $later   = $this->makeDebit($accountId, $counterparty, 'bbbbbbbb-cccc-4bbb-8bbb-bbbbbbbbbbbb',
            new \DateTimeImmutable('2026-04-10 08:00:00', new \DateTimeZone('UTC')), 200, 9_700);

        $cutoff = new \DateTimeImmutable('2026-04-15 00:00:00', new \DateTimeZone('UTC'));

        $found = $this->repository->findLastEntryBefore($accountId, $cutoff);

        self::assertNotNull($found);
        self::assertSame($later->getId()->toString(), $found->getId()->toString());
    }

    public function testFindLastEntryBeforeReturnsNullWhenNoPriorActivity(): void
    {
        // Account with no ledger entries at all
        $accountId = AccountId::fromString('00000000-0000-4000-8000-000000000099');
        $cutoff    = new \DateTimeImmutable('2026-12-31 23:59:59', new \DateTimeZone('UTC'));

        $result = $this->repository->findLastEntryBefore($accountId, $cutoff);

        self::assertNull($result);
    }

    // ── findLastEntryForAccount() ─────────────────────────────────────────────

    public function testFindLastEntryForAccountReturnsMostRecentOverall(): void
    {
        $accountId    = AccountId::fromString('eeeeeeee-cccc-4eee-aeee-eeeeeeeeeeee');
        $counterparty = AccountId::fromString('ffffffff-dddd-4fff-afff-ffffffffffff');

        $first  = $this->makeDebit($accountId, $counterparty, 'aaaaaaaa-dddd-4aaa-8aaa-111111111111',
            new \DateTimeImmutable('2026-05-01 00:00:00', new \DateTimeZone('UTC')), 100, 9_900);
        $second = $this->makeDebit($accountId, $counterparty, 'bbbbbbbb-dddd-4bbb-8bbb-222222222222',
            new \DateTimeImmutable('2026-05-20 00:00:00', new \DateTimeZone('UTC')), 500, 9_400);

        $found = $this->repository->findLastEntryForAccount($accountId);

        self::assertNotNull($found);
        self::assertSame($second->getId()->toString(), $found->getId()->toString());
        self::assertSame(9_400, $found->getBalanceAfterMinorUnits());
    }

    // ── Unique constraint ─────────────────────────────────────────────────────

    /**
     * INSERT IGNORE silently drops the second save() — no exception is thrown.
     * The UNIQUE KEY on (account_id, transfer_id, entry_type) is the guard.
     * Verify by checking the row count stays at 1.
     */
    public function testUniqueConstraintPreventsDuplicateDebitForSameTransfer(): void
    {
        $accountId    = AccountId::fromString('aaaaaaaa-eeee-4aaa-8aaa-aaaaaaaaaaaa');
        $counterparty = AccountId::fromString('bbbbbbbb-ffff-4bbb-8bbb-bbbbbbbbbbbb');
        $transferId   = 'cccccccc-eeee-4ccc-8ccc-cccccccccccc';
        $occurredAt   = new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'));

        $entry1 = LedgerEntry::recordDebit(
            accountId:              $accountId,
            counterpartyAccountId:  $counterparty,
            transferId:             $transferId,
            transferType:           'transfer',
            amountMinorUnits:       1_000,
            currency:               'USD',
            balanceAfterMinorUnits: 9_000,
            occurredAt:             $occurredAt,
        );
        $this->repository->save($entry1);
        $this->track($entry1);

        // Second debit for same (account_id, transfer_id, entry_type) — INSERT IGNORE silences it
        $entry2 = LedgerEntry::recordDebit(
            accountId:              $accountId,
            counterpartyAccountId:  $counterparty,
            transferId:             $transferId,
            transferType:           'transfer',
            amountMinorUnits:       1_000,
            currency:               'USD',
            balanceAfterMinorUnits: 9_000,
            occurredAt:             $occurredAt,
        );
        // Must not throw — INSERT IGNORE swallows the duplicate
        $this->repository->save($entry2);
        // entry2 was not inserted, so track only entry1 to avoid phantom cleanup
        // (entry2 has a different ID generated by LedgerEntryId::generate() but
        // was never written, so no cleanup needed for it)

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE account_id = ? AND transfer_id = ? AND entry_type = ?',
            [$accountId->toString(), $transferId, EntryType::DEBIT->value],
        );

        self::assertSame(1, $count, 'Duplicate debit must be silently ignored — exactly 1 row expected');
    }

    public function testUniqueConstraintAllowsDebitAndCreditForSameTransfer(): void
    {
        $accountId    = AccountId::fromString('dddddddd-eeee-4ddd-addd-dddddddddddd');
        $counterparty = AccountId::fromString('eeeeeeee-ffff-4eee-aeee-eeeeeeeeeeee');
        $transferId   = 'ffffffff-eeee-4fff-afff-ffffffffffff';
        $occurredAt   = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));

        $debit = LedgerEntry::recordDebit(
            accountId:              $accountId,
            counterpartyAccountId:  $counterparty,
            transferId:             $transferId,
            transferType:           'transfer',
            amountMinorUnits:       2_000,
            currency:               'USD',
            balanceAfterMinorUnits: 8_000,
            occurredAt:             $occurredAt,
        );

        $credit = LedgerEntry::recordCredit(
            accountId:              $counterparty,
            counterpartyAccountId:  $accountId,
            transferId:             $transferId,
            transferType:           'transfer',
            amountMinorUnits:       2_000,
            currency:               'USD',
            balanceAfterMinorUnits: 2_000,
            occurredAt:             $occurredAt,
        );

        // Both must save without exception — different entry_type values satisfy the unique key
        $this->repository->save($debit);
        $this->track($debit);
        $this->repository->save($credit);
        $this->track($credit);

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$transferId],
        );

        self::assertSame(2, $count, 'One debit + one credit for same transferId must both be stored');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function makeDebit(
        AccountId          $accountId,
        AccountId          $counterparty,
        string             $transferId,
        \DateTimeImmutable $occurredAt,
        int                $amount,
        int                $balanceAfter,
    ): LedgerEntry {
        $entry = LedgerEntry::recordDebit(
            accountId:              $accountId,
            counterpartyAccountId:  $counterparty,
            transferId:             $transferId,
            transferType:           'transfer',
            amountMinorUnits:       $amount,
            currency:               'USD',
            balanceAfterMinorUnits: $balanceAfter,
            occurredAt:             $occurredAt,
        );
        $this->repository->save($entry);
        $this->track($entry);

        return $entry;
    }

    private function track(LedgerEntry $entry): void
    {
        $this->insertedIds[] = $entry->getId()->toString();
    }
}
