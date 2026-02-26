<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Infrastructure\Reconciliation;

use App\Shared\Application\DTO\ReconciliationRow;
use App\Shared\Infrastructure\Reconciliation\DbalReconciliationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DbalReconciliationRepository against the real MySQL container.
 *
 * Verifies:
 *   — accounts with no ledger entries get ledger_balance = null
 *   — accounts with one ledger entry get the correct balance_after_minor_units
 *   — the window function returns the LATEST entry (by occurred_at DESC, id DESC),
 *     not the first inserted
 *   — multiple accounts are all returned (no status filter)
 *   — the raw diff between account balance and ledger balance is returned as-is
 *     (discrepancy classification is the service's responsibility)
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 *
 * Each test inserts rows with fixed IDs within a specific suffix range (00000000XX)
 * to avoid collisions with other test classes or dev data.  tearDown() deletes
 * only the rows inserted in this test run.
 */
final class DbalReconciliationRepositoryTest extends TestCase
{
    private Connection                   $connection;
    private DbalReconciliationRepository $repo;

    /** @var list<string> Account IDs to clean up in tearDown */
    private array $accountIds = [];

    /** @var list<string> Ledger entry IDs to clean up in tearDown */
    private array $ledgerIds = [];

    private const DATETIME_FORMAT = 'Y-m-d H:i:s.u';

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?',
        );

        $this->connection = DriverManager::getConnection(['url' => $url]);
        $this->repo       = new DbalReconciliationRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->ledgerIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->ledgerIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM ledger_entries WHERE id IN ($placeholders)",
                $this->ledgerIds,
            );
        }

        if ($this->accountIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->accountIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM accounts WHERE id IN ($placeholders)",
                $this->accountIds,
            );
        }

        $this->connection->close();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insertAccount(string $id, int $balance, string $currency = 'USD'): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::DATETIME_FORMAT);

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO accounts
                (id, owner_name, currency, balance_minor_units, status, created_at, updated_at, version)
            VALUES
                (:id, 'Reconciliation Test', :currency, :balance, 'active', :created_at, :updated_at, 1)
            SQL,
            [
                'id'         => $id,
                'currency'   => $currency,
                'balance'    => $balance,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $this->accountIds[] = $id;
    }

    /**
     * Inserts a credit ledger entry.  entry_type = 'credit', transfer_type = 'transfer'.
     * amount_minor_units is fixed at 1_000 — only balance_after_minor_units matters here.
     */
    private function insertLedgerEntry(
        string $entryId,
        string $accountId,
        string $counterpartyId,
        string $transferId,
        int    $balanceAfter,
        string $occurredAt,
        string $currency = 'USD',
    ): void {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::DATETIME_FORMAT);

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO ledger_entries
                (id, account_id, counterparty_account_id, transfer_id,
                 entry_type, transfer_type, amount_minor_units, currency,
                 balance_after_minor_units, occurred_at, created_at)
            VALUES
                (:id, :account_id, :counterparty_id, :transfer_id,
                 'credit', 'transfer', 1000, :currency,
                 :balance_after, :occurred_at, :created_at)
            SQL,
            [
                'id'              => $entryId,
                'account_id'      => $accountId,
                'counterparty_id' => $counterpartyId,
                'transfer_id'     => $transferId,
                'currency'        => $currency,
                'balance_after'   => $balanceAfter,
                'occurred_at'     => $occurredAt,
                'created_at'      => $now,
            ],
        );

        $this->ledgerIds[] = $entryId;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function testAccountWithNoLedgerEntriesHasNullLedgerBalance(): void
    {
        $accountId = 'aaaaaaaa-0000-4000-8000-000000000001';
        $this->insertAccount($accountId, 5_000);

        $row = $this->findRow($this->repo->findAllForReconciliation(), $accountId);

        self::assertNotNull($row, 'Account should appear in reconciliation results');
        self::assertNull($row->ledgerBalance);
        self::assertSame(5_000, $row->accountBalance);
        self::assertSame('USD', $row->currency);
    }

    public function testAccountWithOneLedgerEntryHasCorrectLedgerBalance(): void
    {
        $accountId      = 'aaaaaaaa-0000-4000-8000-000000000002';
        $counterpartyId = 'bbbbbbbb-0000-4000-8000-000000000002';
        $transferId     = 'cccccccc-0000-4000-8000-000000000002';
        $entryId        = '01960000-0000-7000-8000-000000000002';

        $this->insertAccount($accountId, 8_000);
        $this->insertLedgerEntry($entryId, $accountId, $counterpartyId, $transferId, 8_000, '2026-01-15 10:00:00.000000');

        $row = $this->findRow($this->repo->findAllForReconciliation(), $accountId);

        self::assertNotNull($row);
        self::assertSame(8_000, $row->accountBalance);
        self::assertSame(8_000, $row->ledgerBalance);
        self::assertSame('USD', $row->currency);
    }

    public function testLatestLedgerEntryIsSelectedWhenMultipleExist(): void
    {
        $accountId      = 'aaaaaaaa-0000-4000-8000-000000000003';
        $counterpartyId = 'bbbbbbbb-0000-4000-8000-000000000003';
        $transferId1    = 'cccccccc-0000-4000-8000-000000000031';
        $transferId2    = 'cccccccc-0000-4000-8000-000000000032';
        // UUIDv7 IDs: id2 > id1 lexicographically (later UUID = larger value)
        $entryId1       = '01960001-0000-7000-8000-000000000031'; // occurred_at 10:00
        $entryId2       = '01960002-0000-7000-8000-000000000032'; // occurred_at 11:00

        $this->insertAccount($accountId, 15_000);
        // Earlier entry: balance_after = 10_000 (occurred_at 10:00)
        $this->insertLedgerEntry($entryId1, $accountId, $counterpartyId, $transferId1, 10_000, '2026-01-15 10:00:00.000000');
        // Later entry: balance_after = 15_000 (occurred_at 11:00)
        $this->insertLedgerEntry($entryId2, $accountId, $counterpartyId, $transferId2, 15_000, '2026-01-15 11:00:00.000000');

        $row = $this->findRow($this->repo->findAllForReconciliation(), $accountId);

        self::assertNotNull($row);
        // Window function must return the LATEST entry (15_000), not the first (10_000)
        self::assertSame(15_000, $row->ledgerBalance, 'Expected the most recent ledger balance (15 000), not the earlier one (10 000)');
        self::assertSame(15_000, $row->accountBalance);
    }

    public function testMultipleAccountsAreAllPresent(): void
    {
        $accountId1     = 'aaaaaaaa-0000-4000-8000-000000000004';
        $accountId2     = 'bbbbbbbb-0000-4000-8000-000000000004';
        $counterpartyId = 'cccccccc-0000-4000-8000-000000000004';
        $transferId     = 'dddddddd-0000-4000-8000-000000000004';
        $entryId        = '01960003-0000-7000-8000-000000000041';

        // Account 1: no ledger entries
        $this->insertAccount($accountId1, 3_000);
        // Account 2: has one ledger entry
        $this->insertAccount($accountId2, 7_000);
        $this->insertLedgerEntry($entryId, $accountId2, $accountId1, $transferId, 7_000, '2026-01-10 09:00:00.000000');

        $results = $this->repo->findAllForReconciliation();
        $row1    = $this->findRow($results, $accountId1);
        $row2    = $this->findRow($results, $accountId2);

        self::assertNotNull($row1, 'Account 1 (no ledger entries) must be in results');
        self::assertNotNull($row2, 'Account 2 (has ledger entry) must be in results');
        self::assertNull($row1->ledgerBalance, 'Account 1 has no ledger entries — ledgerBalance should be null');
        self::assertSame(7_000, $row2->ledgerBalance, 'Account 2 ledger balance should be 7_000');
    }

    public function testRawDiscrepancyIsReturnedAsIs(): void
    {
        // Simulate a corrupted state: account balance ≠ ledger balance_after.
        // The repository must NOT filter these out — discrepancy detection belongs in the service.
        $accountId      = 'aaaaaaaa-0000-4000-8000-000000000005';
        $counterpartyId = 'bbbbbbbb-0000-4000-8000-000000000005';
        $transferId     = 'cccccccc-0000-4000-8000-000000000005';
        $entryId        = '01960004-0000-7000-8000-000000000051';

        $this->insertAccount($accountId, 9_000);           // live balance = 9_000
        $this->insertLedgerEntry($entryId, $accountId, $counterpartyId, $transferId, 8_500, '2026-01-20 12:00:00.000000'); // ledger says 8_500

        $row = $this->findRow($this->repo->findAllForReconciliation(), $accountId);

        self::assertNotNull($row);
        self::assertSame(9_000, $row->accountBalance);
        self::assertSame(8_500, $row->ledgerBalance);
        // diff is intentionally NOT computed here — that belongs in ReconcileBalancesService
    }

    public function testZeroBalanceAccountWithNoEntriesIsReturned(): void
    {
        $accountId = 'aaaaaaaa-0000-4000-8000-000000000006';
        $this->insertAccount($accountId, 0);

        $row = $this->findRow($this->repo->findAllForReconciliation(), $accountId);

        self::assertNotNull($row);
        self::assertSame(0, $row->accountBalance);
        self::assertNull($row->ledgerBalance);
    }

    public function testFrozenAccountIsIncludedInResults(): void
    {
        // Frozen accounts can still have ledger discrepancies — no status filter applied.
        $accountId = 'aaaaaaaa-0000-4000-8000-000000000007';
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::DATETIME_FORMAT);

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO accounts
                (id, owner_name, currency, balance_minor_units, status, created_at, updated_at, version)
            VALUES
                (:id, 'Reconciliation Test', 'USD', 2000, 'frozen', :created_at, :updated_at, 1)
            SQL,
            ['id' => $accountId, 'created_at' => $now, 'updated_at' => $now],
        );
        $this->accountIds[] = $accountId;

        $row = $this->findRow($this->repo->findAllForReconciliation(), $accountId);

        self::assertNotNull($row, 'Frozen account must be included in reconciliation results');
        self::assertSame(2000, $row->accountBalance);
        self::assertNull($row->ledgerBalance);
    }

    public function testClosedAccountIsIncludedInResults(): void
    {
        // Closed accounts can still have ledger discrepancies — no status filter applied.
        $accountId = 'aaaaaaaa-0000-4000-8000-000000000008';
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::DATETIME_FORMAT);

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO accounts
                (id, owner_name, currency, balance_minor_units, status, created_at, updated_at, version)
            VALUES
                (:id, 'Reconciliation Test', 'EUR', 0, 'closed', :created_at, :updated_at, 1)
            SQL,
            ['id' => $accountId, 'created_at' => $now, 'updated_at' => $now],
        );
        $this->accountIds[] = $accountId;

        $row = $this->findRow($this->repo->findAllForReconciliation(), $accountId);

        self::assertNotNull($row, 'Closed account must be included in reconciliation results');
        self::assertSame(0, $row->accountBalance);
        self::assertNull($row->ledgerBalance);
    }

    public function testTieBreakingByIdDescWhenOccurredAtIdentical(): void
    {
        // Two entries with the exact same occurred_at — the one with the lexicographically
        // larger id (UUIDv7 ordering: id2 > id1) must win, simulating a same-microsecond write.
        $accountId      = 'aaaaaaaa-0000-4000-8000-000000000009';
        $counterpartyId = 'bbbbbbbb-0000-4000-8000-000000000009';
        $transferId1    = 'cccccccc-0000-4000-8000-000000000091';
        $transferId2    = 'cccccccc-0000-4000-8000-000000000092';
        // id2 sorts AFTER id1 lexicographically — should be picked as the "latest"
        $entryId1       = '01960010-0000-7000-8000-000000000091'; // balance_after = 5_000
        $entryId2       = '01960011-0000-7000-8000-000000000092'; // balance_after = 7_000 (should win)
        $sameOccurredAt = '2026-02-01 10:00:00.000000';

        $this->insertAccount($accountId, 7_000);
        $this->insertLedgerEntry($entryId1, $accountId, $counterpartyId, $transferId1, 5_000, $sameOccurredAt);
        $this->insertLedgerEntry($entryId2, $accountId, $counterpartyId, $transferId2, 7_000, $sameOccurredAt);

        $row = $this->findRow($this->repo->findAllForReconciliation(), $accountId);

        self::assertNotNull($row);
        self::assertSame(7_000, $row->ledgerBalance, 'LATERAL ORDER BY id DESC must pick the higher UUID when occurred_at is identical');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param list<ReconciliationRow> $rows
     */
    private function findRow(array $rows, string $accountId): ?ReconciliationRow
    {
        foreach ($rows as $row) {
            if ($row->accountId === $accountId) {
                return $row;
            }
        }

        return null;
    }
}
