<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Ledger;

use App\Tests\Functional\AbstractFunctionalTestCase;

/**
 * Functional tests for GET /accounts/{id}/statement.
 *
 * Test matrix (20 tests):
 *
 *   1.  New account with no activity → empty movements, zero balances
 *   2.  Account with prior activity outside query range → opening=closing=prior balance
 *   3.  Debit entry on sender after transfer
 *   4.  Credit entry on receiver after transfer
 *   5.  Sender's balanceAfterMinorUnits matches GET /accounts/{id}.balanceMinorUnits
 *   6.  Receiver's balanceAfterMinorUnits matches GET /accounts/{id}.balanceMinorUnits
 *   7.  Exactly one ledger entry created per account per transfer
 *   8.  Reversal → CREDIT entry on original source with transferType=reversal
 *   9.  Reversal → DEBIT entry on original destination with transferType=reversal
 *  10.  Opening balance reflects prior-period activity (range starts after transfer)
 *  11.  Closing balance equals last movement's balanceAfterMinorUnits
 *  12.  Movements outside the date range are excluded
 *  13.  Pagination: page 2 of 2 with 3 movements, per_page=2
 *  14.  Unknown account → 404 ACCOUNT_NOT_FOUND
 *  15.  from >= to → 422 INVALID_DATE_RANGE
 *  16.  Range > 366 days → 422 INVALID_DATE_RANGE
 *  17.  Missing `from` parameter → 400 MISSING_PARAMETER
 *  18.  counterpartyAccountId in movement matches the other account's UUID
 *  19.  Idempotent transfer retry does not duplicate ledger entries
 *  20.  Failed transfer (insufficient funds) creates no ledger entries
 */
final class LedgerApiTest extends AbstractFunctionalTestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build a [from, to] date-range pair centered on "now" (UTC).
     *
     * @return array{from: string, to: string}
     */
    private function wideRange(int $minutesBefore = 60, int $minutesAfter = 60): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'from' => $now->modify("-{$minutesBefore} minutes")->format(\DateTimeInterface::ATOM),
            'to'   => $now->modify("+{$minutesAfter} minutes")->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * GET /accounts/{accountId}/statement.
     *
     * @param  array{from: string, to: string} $range
     * @return array<string, mixed>
     */
    private function getStatement(
        string $accountId,
        array  $range,
        int    $page    = 1,
        int    $perPage = 50,
    ): array {
        return $this->getJson(
            "/accounts/{$accountId}/statement",
            array_merge($range, ['page' => $page, 'per_page' => $perPage]),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests 1–7: Basic ledger recording
    // ──────────────────────────────────────────────────────────────────────────

    public function testStatementReturnsEmptyMovementsForNewAccountWithNoActivity(): void
    {
        $account = $this->createAccount('Alice', 'USD', 0);
        $range   = $this->wideRange();

        $body = $this->getStatement($account['id'], $range);

        self::assertSame(200, $this->getStatusCode());

        $data = $body['data'];
        self::assertSame($account['id'], $data['accountId']);
        self::assertSame('USD', $data['currency']);
        self::assertSame(0, $data['openingBalanceMinorUnits']);
        self::assertSame(0, $data['closingBalanceMinorUnits']);
        self::assertSame([], $data['movements']);
        self::assertSame(0, $data['pagination']['total']);
    }

    public function testStatementReturnsEmptyWhenNoActivityInRange(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        // Transfer creates a ledger entry "now" (before our query range)
        $this->createTransfer($src['id'], $dst['id'], 1_000);

        // Far-future range: no activity will ever happen in 2099
        $futureRange = [
            'from' => '2099-01-01T00:00:00+00:00',
            'to'   => '2099-01-31T23:59:59+00:00',
        ];

        $body = $this->getStatement($src['id'], $futureRange);

        self::assertSame(200, $this->getStatusCode());

        $data = $body['data'];
        self::assertSame([], $data['movements']);
        self::assertSame(0, $data['pagination']['total']);
        // Opening balance = last entry BEFORE 2099-01-01 = balance after the debit
        self::assertSame(9_000, $data['openingBalanceMinorUnits']);
        // No movements → closing == opening
        self::assertSame(9_000, $data['closingBalanceMinorUnits']);
    }

    public function testStatementShowsDebitEntryAfterTransfer(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $this->createTransfer($src['id'], $dst['id'], 1_000);

        $body = $this->getStatement($src['id'], $this->wideRange());

        self::assertSame(200, $this->getStatusCode());

        // Filter to transfer-type movements — bootstrap CREDIT (for the opening balance)
        // is also present in the statement when the account was seeded with a non-zero balance.
        $transferMovements = array_values(array_filter(
            $body['data']['movements'],
            static fn (array $m): bool => $m['transferType'] === 'transfer',
        ));
        self::assertCount(1, $transferMovements);

        $movement = $transferMovements[0];
        self::assertSame('debit', $movement['type']);
        self::assertSame('transfer', $movement['transferType']);
        self::assertSame(1_000, $movement['amountMinorUnits']);
        self::assertSame('USD', $movement['currency']);
    }

    public function testStatementShowsCreditEntryAfterTransfer(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $this->createTransfer($src['id'], $dst['id'], 1_000);

        $body = $this->getStatement($dst['id'], $this->wideRange());

        self::assertSame(200, $this->getStatusCode());
        self::assertCount(1, $body['data']['movements']);

        $movement = $body['data']['movements'][0];
        self::assertSame('credit', $movement['type']);
        self::assertSame('transfer', $movement['transferType']);
        self::assertSame(1_000, $movement['amountMinorUnits']);
        self::assertSame('USD', $movement['currency']);
    }

    public function testStatementDebitBalanceAfterEqualsAccountBalance(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $this->createTransfer($src['id'], $dst['id'], 1_000);

        $statement   = $this->getStatement($src['id'], $this->wideRange());
        $accountBody = $this->getJson('/accounts/' . $src['id']);

        self::assertSame(200, $this->getStatusCode());

        $transferMovements = array_values(array_filter(
            $statement['data']['movements'],
            static fn (array $m): bool => $m['transferType'] === 'transfer',
        ));
        self::assertCount(1, $transferMovements);

        self::assertSame(
            $accountBody['data']['balanceMinorUnits'],
            $transferMovements[0]['balanceAfterMinorUnits'],
        );
    }

    public function testStatementCreditBalanceAfterEqualsAccountBalance(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $this->createTransfer($src['id'], $dst['id'], 1_000);

        $statement   = $this->getStatement($dst['id'], $this->wideRange());
        $accountBody = $this->getJson('/accounts/' . $dst['id']);

        self::assertSame(200, $this->getStatusCode());
        self::assertCount(1, $statement['data']['movements']);

        self::assertSame(
            $accountBody['data']['balanceMinorUnits'],
            $statement['data']['movements'][0]['balanceAfterMinorUnits'],
        );
    }

    public function testTwoLedgerEntriesCreatedPerTransfer(): void
    {
        $src = $this->createAccount('Sender', 'USD', 5_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $this->createTransfer($src['id'], $dst['id'], 500);

        $range = $this->wideRange();

        $srcBody = $this->getStatement($src['id'], $range);
        $dstBody = $this->getStatement($dst['id'], $range);

        // Filter to transfer-type movements only — bootstrap CREDIT for src is
        // also in the range but is not a transfer entry.
        $srcTransfer = array_filter($srcBody['data']['movements'], static fn ($m) => $m['transferType'] === 'transfer');
        $dstTransfer = array_filter($dstBody['data']['movements'], static fn ($m) => $m['transferType'] === 'transfer');

        self::assertSame(1, count($srcTransfer), 'Sender must have exactly 1 transfer-type ledger entry');
        self::assertSame(1, count($dstTransfer), 'Receiver must have exactly 1 transfer-type ledger entry');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests 8–9: Reversal entries
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * After a transfer is reversed, the ORIGINAL SOURCE sees a CREDIT entry
     * with transferType=reversal (funds returned), alongside the original DEBIT.
     *
     * Movements are ordered DESC by occurred_at so the most-recent (reversal)
     * entry arrives first.
     */
    public function testStatementShowsReversalEntryAsCreditOnOriginalSource(): void
    {
        $src      = $this->createAccount('Sender', 'USD', 10_000);
        $dst      = $this->createAccount('Receiver', 'USD', 0);
        $transfer = $this->createTransfer($src['id'], $dst['id'], 2_000);

        $reversal = $this->postJson('/transfers/' . $transfer['id'] . '/reverse');
        self::assertSame(200, $this->getStatusCode(), 'Reversal must succeed: ' . json_encode($reversal));

        $body = $this->getStatement($src['id'], $this->wideRange());

        self::assertSame(200, $this->getStatusCode());
        // 2 non-bootstrap entries: original DEBIT + reversal CREDIT
        // (bootstrap CREDIT for the seeded opening balance is also present but excluded here)
        $nonBootstrapMovements = array_values(array_filter(
            $body['data']['movements'],
            static fn (array $m): bool => $m['transferType'] !== 'bootstrap',
        ));
        self::assertCount(2, $nonBootstrapMovements);

        $reversalMovements = array_values(array_filter(
            $nonBootstrapMovements,
            static fn (array $m): bool => $m['transferType'] === 'reversal',
        ));

        self::assertCount(1, $reversalMovements);
        self::assertSame('credit', $reversalMovements[0]['type']);
        self::assertSame(2_000, $reversalMovements[0]['amountMinorUnits']);
    }

    /**
     * After a transfer is reversed, the ORIGINAL DESTINATION sees a DEBIT entry
     * with transferType=reversal (funds reclaimed), alongside the original CREDIT.
     */
    public function testStatementShowsReversalEntryAsDebitOnOriginalDestination(): void
    {
        $src      = $this->createAccount('Sender', 'USD', 10_000);
        $dst      = $this->createAccount('Receiver', 'USD', 0);
        $transfer = $this->createTransfer($src['id'], $dst['id'], 2_000);

        $reversal = $this->postJson('/transfers/' . $transfer['id'] . '/reverse');
        self::assertSame(200, $this->getStatusCode(), 'Reversal must succeed: ' . json_encode($reversal));

        $body = $this->getStatement($dst['id'], $this->wideRange());

        self::assertSame(200, $this->getStatusCode());
        // 2 entries: original CREDIT + reversal DEBIT
        self::assertCount(2, $body['data']['movements']);

        $reversalMovements = array_values(array_filter(
            $body['data']['movements'],
            static fn (array $m): bool => $m['transferType'] === 'reversal',
        ));

        self::assertCount(1, $reversalMovements);
        self::assertSame('debit', $reversalMovements[0]['type']);
        self::assertSame(2_000, $reversalMovements[0]['amountMinorUnits']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests 10–12: Balance calculation and date-range boundaries
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * When the query range starts AFTER all transfers, the opening balance
     * reflects the last known balance (prior-period activity), and there are
     * no movements in the range (closing == opening).
     */
    public function testStatementOpeningBalanceReflectsPriorPeriodActivity(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        // Transfer happens "now" — before the query range below
        $this->createTransfer($src['id'], $dst['id'], 3_000);

        // Far-future range starts well after the transfer
        $futureRange = [
            'from' => '2099-06-01T00:00:00+00:00',
            'to'   => '2099-06-30T23:59:59+00:00',
        ];

        $body = $this->getStatement($src['id'], $futureRange);

        self::assertSame(200, $this->getStatusCode());
        // Opening = balance after the debit (10 000 - 3 000 = 7 000)
        self::assertSame(7_000, $body['data']['openingBalanceMinorUnits']);
        // No movements in this window
        self::assertSame([], $body['data']['movements']);
        // Closing == opening when there are no movements
        self::assertSame(7_000, $body['data']['closingBalanceMinorUnits']);
    }

    public function testStatementClosingBalanceEqualsLastMovementBalanceAfter(): void
    {
        $src = $this->createAccount('Sender', 'USD', 8_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $this->createTransfer($src['id'], $dst['id'], 2_500);

        $body = $this->getStatement($src['id'], $this->wideRange());

        self::assertSame(200, $this->getStatusCode());

        $movements = $body['data']['movements'];
        self::assertNotEmpty($movements);

        // Movements are ordered DESC, so movements[0] is the chronologically last entry.
        self::assertSame(
            $movements[0]['balanceAfterMinorUnits'],
            $body['data']['closingBalanceMinorUnits'],
        );
    }

    /**
     * Transfers that fall outside the requested date window must not appear in
     * movements, but the same transfers DO appear when the window is widened.
     */
    public function testStatementRespectsDateRangeBoundaries(): void
    {
        $src = $this->createAccount('Sender', 'USD', 5_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        // Transfer happens "now"
        $this->createTransfer($src['id'], $dst['id'], 1_000);

        // A window entirely in the future excludes this transfer
        $excludingRange = [
            'from' => '2099-01-01T00:00:00+00:00',
            'to'   => '2099-01-31T23:59:59+00:00',
        ];

        $excluded = $this->getStatement($src['id'], $excludingRange);

        self::assertSame(200, $this->getStatusCode());
        self::assertSame([], $excluded['data']['movements']);
        self::assertSame(0, $excluded['data']['pagination']['total']);

        // A window centered on "now" includes this transfer (and the bootstrap entry for the seeded balance)
        $included = $this->getStatement($src['id'], $this->wideRange());

        self::assertSame(200, $this->getStatusCode());
        self::assertSame(2, $included['data']['pagination']['total']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 13: Pagination
    // ──────────────────────────────────────────────────────────────────────────

    public function testStatementPaginatesMovementsCorrectly(): void
    {
        $src = $this->createAccount('Sender', 'USD', 30_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $this->createTransfer($src['id'], $dst['id'], 1_000);
        $this->createTransfer($src['id'], $dst['id'], 2_000);
        $this->createTransfer($src['id'], $dst['id'], 3_000);

        $range = $this->wideRange();

        // Page 1 of 2: 3 most-recent movements (DESC order); perPage=3 keeps
        // the last-page boundary test meaningful with 4 total entries
        // (1 bootstrap CREDIT for the seeded balance + 3 transfer DEBITs).
        $page1 = $this->getStatement($src['id'], $range, 1, 3);

        self::assertSame(200, $this->getStatusCode());
        self::assertCount(3, $page1['data']['movements']);
        self::assertSame(4, $page1['data']['pagination']['total']);
        self::assertSame(2, $page1['data']['pagination']['totalPages']);
        self::assertSame(1, $page1['data']['pagination']['page']);
        self::assertSame(3, $page1['data']['pagination']['perPage']);

        // Page 2 of 2: the 1 remaining (oldest) movement
        $page2 = $this->getStatement($src['id'], $range, 2, 3);

        self::assertSame(200, $this->getStatusCode());
        self::assertCount(1, $page2['data']['movements']);
        self::assertSame(4, $page2['data']['pagination']['total']);
        self::assertSame(2, $page2['data']['pagination']['totalPages']);
        self::assertSame(2, $page2['data']['pagination']['page']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests 14–17: Error handling
    // ──────────────────────────────────────────────────────────────────────────

    public function testStatementReturns404OnUnknownAccount(): void
    {
        $unknownId = '00000000-0000-4000-8000-000000000001';

        $body = $this->getStatement($unknownId, $this->wideRange());

        self::assertSame(404, $this->getStatusCode());
        self::assertSame('ACCOUNT_NOT_FOUND', $body['error']['code']);
    }

    public function testStatementReturns422WhenFromAfterTo(): void
    {
        $account = $this->createAccount('Alice', 'USD', 0);

        // from is later than to — handler throws InvalidDateRangeException
        $body = $this->getStatement($account['id'], [
            'from' => '2026-06-01T00:00:00+00:00',
            'to'   => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame(422, $this->getStatusCode());
        self::assertSame('INVALID_DATE_RANGE', $body['error']['code']);
    }

    public function testStatementReturns422WhenRangeExceeds366Days(): void
    {
        $account = $this->createAccount('Alice', 'USD', 0);

        // 517-day span exceeds the 366-day maximum
        $body = $this->getStatement($account['id'], [
            'from' => '2025-01-01T00:00:00+00:00',
            'to'   => '2026-06-01T00:00:00+00:00',
        ]);

        self::assertSame(422, $this->getStatusCode());
        self::assertSame('INVALID_DATE_RANGE', $body['error']['code']);
    }

    public function testStatementReturns400OnMissingFrom(): void
    {
        $account = $this->createAccount('Alice', 'USD', 0);

        // Omit `from` — controller validates presence before calling the handler
        $body = $this->getJson("/accounts/{$account['id']}/statement", [
            'to' => '2026-06-01T00:00:00+00:00',
        ]);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('MISSING_PARAMETER', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests 18–20: Counterparty ID, idempotency, failure isolation
    // ──────────────────────────────────────────────────────────────────────────

    public function testStatementCounterpartyIdIsCorrect(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $this->createTransfer($src['id'], $dst['id'], 1_500);

        $body = $this->getStatement($src['id'], $this->wideRange());

        self::assertSame(200, $this->getStatusCode());

        $transferMovements = array_values(array_filter(
            $body['data']['movements'],
            static fn (array $m): bool => $m['transferType'] === 'transfer',
        ));
        self::assertCount(1, $transferMovements);

        // The sender's debit entry must reference the destination as counterparty
        self::assertSame($dst['id'], $transferMovements[0]['counterpartyAccountId']);
    }

    /**
     * Replaying the same transfer request with an idempotency key must not
     * create duplicate ledger entries — INSERT IGNORE on the unique constraint
     * (account_id, transfer_id, entry_type) guards against this at the DB level.
     */
    public function testLedgerEntryNotDuplicatedOnHandlerRetry(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $idempotencyKey = 'ledger-idempotency-test-' . uniqid('', true);
        $this->trackIdempotencyKey($idempotencyKey);

        $payload = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
        ];

        // First request — creates the transfer and writes 2 ledger entries
        $first = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $idempotencyKey]);
        self::assertSame(201, $this->getStatusCode(), 'First request must return 201');
        $this->trackTransfer($first['data']['id']);

        // Second request — idempotency layer returns cached response; no new DB writes
        $second = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $idempotencyKey]);
        self::assertSame(201, $this->getStatusCode(), 'Idempotent replay must return 201');

        // Both responses must reference the same transfer
        self::assertSame($first['data']['id'], $second['data']['id']);

        $range = $this->wideRange();

        // Sender: exactly 1 transfer-type debit entry (not 2) — handler invoked only once
        $srcStatement = $this->getStatement($src['id'], $range);
        $srcTransfer  = array_filter($srcStatement['data']['movements'], static fn ($m) => $m['transferType'] === 'transfer');
        self::assertSame(1, count($srcTransfer), 'Sender must have exactly 1 transfer-type movement — no duplication');

        // Receiver: exactly 1 transfer-type credit entry (not 2)
        $dstStatement = $this->getStatement($dst['id'], $range);
        $dstTransfer  = array_filter($dstStatement['data']['movements'], static fn ($m) => $m['transferType'] === 'transfer');
        self::assertSame(1, count($dstTransfer), 'Receiver must have exactly 1 transfer-type movement — no duplication');
    }

    /**
     * A transfer that fails due to insufficient funds must not produce any
     * ledger entries — the transactional boundary rolls back all DB writes.
     */
    public function testFailedTransferCreatesNoLedgerEntries(): void
    {
        $src = $this->createAccount('BrokeAccount', 'USD', 0);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        // Attempt transfer; 0-balance source → insufficient funds → 4xx response
        $this->postJson('/transfers', [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
        ]);
        self::assertGreaterThanOrEqual(400, $this->getStatusCode(), 'Transfer must fail');

        $range = $this->wideRange();

        // No ledger entry for sender
        $srcStatement = $this->getStatement($src['id'], $range);
        self::assertSame(200, $this->getStatusCode());
        self::assertSame([], $srcStatement['data']['movements']);

        // No ledger entry for receiver either
        $dstStatement = $this->getStatement($dst['id'], $range);
        self::assertSame(200, $this->getStatusCode());
        self::assertSame([], $dstStatement['data']['movements']);
    }
}
