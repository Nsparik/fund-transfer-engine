<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\AbstractFunctionalTestCase;
use Doctrine\DBAL\Connection;

/**
 * TC-3 — Idempotency Race Condition
 *
 * Fires 20 sequential requests with the same X-Idempotency-Key and verifies
 * that only one transfer is created and exactly two ledger entries exist.
 *
 * ## Why sequential, not concurrent
 *   PHP PHPUnit tests run in a single process. Simulating true concurrency
 *   would require pcntl_fork() process pairs and shared-memory coordination
 *   which adds infrastructure complexity without changing the fundamental
 *   assertion. The race condition that matters financially is between the
 *   application layer (idempotency subscriber) and the database layer (UNIQUE
 *   constraint).  Both are exercised here:
 *
 *   - Requests 2–20 hit the subscriber cache-hit path (SELECT by key found)
 *     and never reach the handler — idempotency subscriber layer.
 *   - If the subscriber were somehow bypassed, the INSERT IGNORE on
 *     ledger_entries(account_id, transfer_id, entry_type) is the database-
 *     level backstop — ledger idempotency layer.
 *
 * ## DB assertions (not response-only)
 *   - COUNT(*) on transfers WHERE source_account_id = source
 *   - COUNT(*) on ledger_entries WHERE transfer_id = created_transfer_id
 *   - Both verified against real MySQL, not inferred from HTTP responses alone.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Functional
 */
final class IdempotencyRaceConditionTest extends AbstractFunctionalTestCase
{
    private const REPEAT_COUNT = 20;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = static::getContainer()->get(Connection::class);
    }

    public function testTwentyRequestsWithSameKeyCreateExactlyOneTransferAndTwoLedgerEntries(): void
    {
        $source = $this->createAccount('Sender', 'USD', 100_000);
        $dest   = $this->createAccount('Receiver', 'USD', 0);

        $key     = 'race-test-' . bin2hex(random_bytes(16));
        $payload = [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
        ];

        $this->trackIdempotencyKey($key);

        $firstTransferId = null;
        $statusCodes     = [];

        // Fire REPEAT_COUNT requests with the same key.
        for ($i = 0; $i < self::REPEAT_COUNT; $i++) {
            $body   = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $key]);
            $status = $this->getStatusCode();
            $statusCodes[] = $status;

            if ($i === 0) {
                self::assertSame(201, $status, 'First request must return 201 Created');
                self::assertArrayHasKey('data', $body, 'First response must have data envelope');
                $firstTransferId = $body['data']['id'];
                $this->trackTransfer($firstTransferId);
            } else {
                self::assertSame(201, $status, "Request #{$i} must return 201 (cached replay)");
                self::assertSame(
                    $firstTransferId,
                    $body['data']['id'],
                    "Request #{$i} must return the same transfer ID as request #0",
                );
            }
        }

        // ── DB assertion 1: exactly 1 transfer row in the DB ─────────────────
        $transferCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM transfers
              WHERE source_account_id = ? AND destination_account_id = ?',
            [$source['id'], $dest['id']],
        );

        self::assertSame(
            1,
            $transferCount,
            sprintf(
                'Exactly 1 transfer must exist in DB after %d requests with the same idempotency key',
                self::REPEAT_COUNT,
            ),
        );

        // ── DB assertion 2: exactly 2 ledger entries for the one transfer ─────
        $ledgerCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$firstTransferId],
        );

        self::assertSame(
            2,
            $ledgerCount,
            sprintf(
                'Exactly 2 ledger entries (1 debit + 1 credit) must exist after %d requests',
                self::REPEAT_COUNT,
            ),
        );

        // ── DB assertion 3: ledger entry types are correct ────────────────────
        $entryTypes = $this->db->fetchFirstColumn(
            'SELECT entry_type FROM ledger_entries WHERE transfer_id = ?',
            [$firstTransferId],
        );

        self::assertContains('debit',  $entryTypes, 'Exactly one debit entry must exist');
        self::assertContains('credit', $entryTypes, 'Exactly one credit entry must exist');

        // ── DB assertion 4: source account balance only debited once ──────────
        $statement = $this->getJson(
            "/accounts/{$source['id']}/statement",
            [
                'from' => (new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'to'   => (new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ],
        );

        $transferMovements = array_filter(
            $statement['data']['movements'],
            static fn (array $m): bool => $m['transferType'] === 'transfer',
        );
        self::assertSame(
            1,
            count($transferMovements),
            'Source account must have exactly 1 ledger debit — handler invoked only once',
        );

        // ── Sanity: all 20 responses returned 201, none returned 5xx ─────────
        $nonSuccessStatuses = array_filter($statusCodes, fn (int $code) => $code >= 500);
        self::assertEmpty($nonSuccessStatuses, '5xx response must never be returned');
    }

    public function testDatabaseLevelUniqueConstraintPreventsLedgerDuplication(): void
    {
        // Simulate the case where the idempotency subscriber is somehow bypassed
        // and the handler is called twice with the same transfer IDs.
        //
        // Verifies that the UNIQUE KEY uidx_ledger_account_transfer_type
        // (account_id, transfer_id, entry_type) prevents a second ledger write
        // via INSERT IGNORE — the DB-level idempotency backstop.
        //
        // This test inserts two rows for the same (account_id, transfer_id, 'credit')
        // and asserts only one row exists, proving INSERT IGNORE is effective.

        $source = $this->createAccount('Source', 'USD', 10_000);
        $dest   = $this->createAccount('Dest', 'USD', 0);

        // First: create a real transfer to get valid IDs
        $key  = 'db-uniqueness-test-' . bin2hex(random_bytes(16));
        $this->trackIdempotencyKey($key);

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 500,
            'currency'             => 'USD',
        ], ['X-Idempotency-Key' => $key]);

        self::assertSame(201, $this->getStatusCode());
        $transferId = $body['data']['id'];
        $this->trackTransfer($transferId);

        // Verify exactly 2 ledger entries exist.
        $initialCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$transferId],
        );
        self::assertSame(2, $initialCount);

        // Attempt to insert a duplicate ledger entry using INSERT IGNORE.
        // This mirrors what would happen if the handler were retried.
        $existingRow = $this->db->fetchAssociative(
            'SELECT account_id, counterparty_account_id, entry_type, amount_minor_units,
                    currency, balance_after_minor_units, occurred_at
             FROM ledger_entries WHERE transfer_id = ? AND entry_type = ?',
            [$transferId, 'credit'],
        );

        self::assertNotFalse($existingRow, 'Credit ledger entry must exist');

        // INSERT IGNORE — duplicate (account_id, transfer_id, entry_type) → silently skipped
        $inserted = $this->db->executeStatement(
            'INSERT IGNORE INTO ledger_entries
                (id, account_id, counterparty_account_id, transfer_id,
                 entry_type, transfer_type, amount_minor_units, currency,
                 balance_after_minor_units, occurred_at, created_at)
             VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6))',
            [
                $existingRow['account_id'],
                $existingRow['counterparty_account_id'],
                $transferId,
                'credit',                                      // same entry_type → UNIQUE fires
                'transfer',
                $existingRow['amount_minor_units'],
                $existingRow['currency'],
                $existingRow['balance_after_minor_units'],
                $existingRow['occurred_at'],
            ],
        );

        self::assertSame(0, (int) $inserted, 'INSERT IGNORE must produce 0 affected rows on duplicate');

        // Ledger count must remain 2.
        $countAfterDuplicateAttempt = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$transferId],
        );

        self::assertSame(2, $countAfterDuplicateAttempt, 'Ledger count must remain 2 after duplicate insert attempt');
    }
}
