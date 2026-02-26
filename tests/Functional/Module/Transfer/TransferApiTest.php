<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Transfer;

use App\Tests\Functional\AbstractFunctionalTestCase;
use Doctrine\DBAL\Connection;

/**
 * Functional tests for the Transfer API — full HTTP-stack coverage.
 *
 * ## Coverage (23 tests — date-filter tests excluded until implemented)
 *
 *   POST /transfers
 *     - Happy path: 201, status=completed, TXN- reference, Location header
 *     - Invalid JSON body → 400 INVALID_JSON
 *     - Missing required fields → 400 VALIDATION_ERROR with violations array
 *     - Zero amount → 400 VALIDATION_ERROR (caught by #[GreaterThan(0)] before domain)
 *     - Same source/destination account → 422 SAME_ACCOUNT_TRANSFER
 *     - Insufficient funds → 422 INSUFFICIENT_FUNDS
 *     - Frozen source account → 409 ACCOUNT_FROZEN
 *     - Frozen destination account → 409 ACCOUNT_FROZEN
 *     - Unknown source account → 404 ACCOUNT_NOT_FOUND
 *     - Unknown destination account → 404 ACCOUNT_NOT_FOUND
 *
 *   GET /transfers/{id}
 *     - Returns 200 with all DTO fields populated
 *     - Unknown UUID → 404 TRANSFER_NOT_FOUND
 *
 *   GET /transfers (list)
 *     - 200 with full pagination envelope (items, total, page, perPage, totalPages)
 *     - status filter returns only matching transfers
 *     - Invalid status value → 400 INVALID_STATUS
 *     - per_page out of range → 400 INVALID_PER_PAGE
 *
 *   POST /transfers/{id}/reverse
 *     - Happy path: 200, status=reversed
 *     - Already-reversed transfer → 409 INVALID_TRANSFER_STATE
 *     - PENDING transfer → 409 INVALID_TRANSFER_STATE (direct DB fixture)
 *     - Unknown transfer → 404 TRANSFER_NOT_FOUND
 *     - Drained destination → 422 INSUFFICIENT_FUNDS
 *
 *   Idempotency (X-Idempotency-Key on POST /transfers)
 *     - Retry with same key + same body → same transfer ID returned (no dup)
 *     - Same key + different body → 422 IDEMPOTENCY_KEY_REUSE
 *
 * ## Notes
 *   - Zero amount returns 400 (validator) not 422 (domain): #[GreaterThan(0)]
 *     on InitiateTransferRequest rejects before the handler is invoked.
 *   - Idempotency mismatch returns 422 not 409: IdempotencySubscriber sets
 *     HTTP_UNPROCESSABLE_ENTITY directly (no domain exception pathway used).
 *   - PENDING-state test uses a direct DBAL insert; the handler moves transfers
 *     through PENDING→PROCESSING→COMPLETED atomically so PENDING is unreachable
 *     via normal HTTP flow.
 *   - Tests 15 & 16 (created_at_from / created_at_range filters) are excluded
 *     pending date-filter implementation.
 */
final class TransferApiTest extends AbstractFunctionalTestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // POST /transfers — happy path
    // ──────────────────────────────────────────────────────────────────────────

    public function testInitiateTransferHappyPath(): void
    {
        $source = $this->createAccount('Alice', 'USD', 50_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
            'description'          => 'Rent payment',
        ]);

        self::assertSame(201, $this->getStatusCode());

        $data = $body['data'];
        self::assertNotEmpty($data['id']);
        self::assertSame('completed', $data['status']);
        self::assertSame(1_000, $data['amountMinorUnits']);
        self::assertSame('USD', $data['currency']);
        self::assertSame('Rent payment', $data['description']);
        self::assertSame($source['id'], $data['sourceAccountId']);
        self::assertSame($dest['id'], $data['destinationAccountId']);
        self::assertNotNull($data['completedAt']);
        self::assertNull($data['failedAt']);
        self::assertNull($data['reversedAt']);

        // Reference must match TXN-YYYYMMDD-XXXXXXXXXXXX format
        self::assertMatchesRegularExpression(
            '/^TXN-\d{8}-[0-9A-F]{12}$/',
            $data['reference'],
        );

        // Location header must point to the new transfer
        self::assertSame('/transfers/' . $data['id'], $this->getResponseHeader('Location'));

        $this->trackTransfer($data['id']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /transfers — validation & error cases
    // ──────────────────────────────────────────────────────────────────────────

    public function testInitiateTransferReturns400OnInvalidJson(): void
    {
        $this->client->request(
            'POST',
            '/transfers',
            [],
            [],
            [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_X_IDEMPOTENCY_KEY' => 'test-invalid-json-' . bin2hex(random_bytes(8)),
            ],
            'not-valid-json{{{',
        );

        self::assertSame(400, $this->getStatusCode());
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('INVALID_JSON', $body['error']['code']);
    }

    public function testInitiateTransferReturns400OnMissingFields(): void
    {
        // Send an empty JSON object — all required fields missing
        $body = $this->postJson('/transfers', []);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
        self::assertArrayHasKey('violations', $body['error']);
        self::assertNotEmpty($body['error']['violations']);

        // Violations must list the missing fields
        $fields = array_column($body['error']['violations'], 'field');
        self::assertContains('sourceAccountId', $fields);
        self::assertContains('destinationAccountId', $fields);
    }

    /**
     * Zero amount is rejected at the validator layer (#[GreaterThan(0)]) → 400,
     * not at the domain layer → not 422 INVALID_TRANSFER_AMOUNT.
     */
    public function testInitiateTransferReturns400OnZeroAmount(): void
    {
        $source = $this->createAccount('Alice', 'USD', 10_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 0,
            'currency'             => 'USD',
        ]);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);

        $fields = array_column($body['error']['violations'], 'field');
        self::assertContains('amountMinorUnits', $fields);
    }

    public function testInitiateTransferReturns422OnSameAccount(): void
    {
        $account = $this->createAccount('Alice', 'USD', 10_000);

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $account['id'],
            'destinationAccountId' => $account['id'],
            'amountMinorUnits'     => 500,
            'currency'             => 'USD',
        ]);

        self::assertSame(422, $this->getStatusCode());
        self::assertSame('SAME_ACCOUNT_TRANSFER', $body['error']['code']);
    }

    public function testInitiateTransferReturns422OnInsufficientFunds(): void
    {
        $source = $this->createAccount('Alice', 'USD', 100);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 9_999_999,
            'currency'             => 'USD',
        ]);

        self::assertSame(422, $this->getStatusCode());
        self::assertSame('INSUFFICIENT_FUNDS', $body['error']['code']);
    }

    public function testInitiateTransferReturns409OnFrozenSource(): void
    {
        $source = $this->createAccount('Frozen Alice', 'USD', 10_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        // Freeze source account
        $this->postJson('/accounts/' . $source['id'] . '/freeze');
        self::assertSame(200, $this->getStatusCode());

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 500,
            'currency'             => 'USD',
        ]);

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('ACCOUNT_FROZEN', $body['error']['code']);
    }

    public function testInitiateTransferReturns409OnFrozenDestination(): void
    {
        $source = $this->createAccount('Alice', 'USD', 10_000);
        $dest   = $this->createAccount('Frozen Bob', 'USD', 0);

        // Freeze destination account
        $this->postJson('/accounts/' . $dest['id'] . '/freeze');
        self::assertSame(200, $this->getStatusCode());

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 500,
            'currency'             => 'USD',
        ]);

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('ACCOUNT_FROZEN', $body['error']['code']);
    }

    public function testInitiateTransferReturns404OnUnknownSource(): void
    {
        $dest = $this->createAccount('Bob', 'USD', 0);

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => '00000000-0000-4000-8000-000000000001',
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 500,
            'currency'             => 'USD',
        ]);

        self::assertSame(404, $this->getStatusCode());
        self::assertSame('ACCOUNT_NOT_FOUND', $body['error']['code']);
    }

    public function testInitiateTransferReturns404OnUnknownDestination(): void
    {
        $source = $this->createAccount('Alice', 'USD', 10_000);

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => '00000000-0000-4000-8000-000000000002',
            'amountMinorUnits'     => 500,
            'currency'             => 'USD',
        ]);

        self::assertSame(404, $this->getStatusCode());
        self::assertSame('ACCOUNT_NOT_FOUND', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /transfers/{id}
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetTransferReturns200(): void
    {
        $source   = $this->createAccount('Alice', 'USD', 20_000);
        $dest     = $this->createAccount('Bob', 'USD', 0);
        $transfer = $this->createTransfer($source['id'], $dest['id'], 2_500, 'USD');

        $body = $this->getJson('/transfers/' . $transfer['id']);

        self::assertSame(200, $this->getStatusCode());

        $data = $body['data'];
        self::assertSame($transfer['id'], $data['id']);
        self::assertSame($source['id'], $data['sourceAccountId']);
        self::assertSame($dest['id'], $data['destinationAccountId']);
        self::assertSame(2_500, $data['amountMinorUnits']);
        self::assertSame('USD', $data['currency']);
        self::assertSame('completed', $data['status']);
        self::assertArrayHasKey('reference', $data);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
        self::assertArrayHasKey('completedAt', $data);
    }

    public function testGetTransferReturns404OnUnknownId(): void
    {
        $body = $this->getJson('/transfers/00000000-0000-4000-8000-000000000099');

        self::assertSame(404, $this->getStatusCode());
        self::assertSame('TRANSFER_NOT_FOUND', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /transfers (list + pagination)
    // ──────────────────────────────────────────────────────────────────────────

    public function testListTransfersReturns200WithPaginationMeta(): void
    {
        $source = $this->createAccount('Alice', 'USD', 30_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);
        $t1     = $this->createTransfer($source['id'], $dest['id'], 1_000, 'USD');
        $t2     = $this->createTransfer($source['id'], $dest['id'], 2_000, 'USD');

        $body = $this->getJson('/transfers', ['per_page' => 100]);

        self::assertSame(200, $this->getStatusCode());

        $data = $body['data'];
        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('total', $data);
        self::assertArrayHasKey('page', $data);
        self::assertArrayHasKey('perPage', $data);
        self::assertArrayHasKey('totalPages', $data);
        self::assertIsArray($data['items']);

        // Both transfers must appear somewhere in the list
        $ids = array_column($data['items'], 'id');
        self::assertContains($t1['id'], $ids);
        self::assertContains($t2['id'], $ids);

        $this->trackTransfer($t1['id']);
        $this->trackTransfer($t2['id']);
    }

    public function testListTransfersFiltersByStatus(): void
    {
        $source = $this->createAccount('Alice', 'USD', 20_000);
        $dest   = $this->createAccount('Bob', 'USD', 10_000);

        // Create a completed transfer
        $completed = $this->createTransfer($source['id'], $dest['id'], 1_000, 'USD');

        // List only completed transfers
        $body = $this->getJson('/transfers', ['status' => 'completed', 'per_page' => 100]);

        self::assertSame(200, $this->getStatusCode());
        $items = $body['data']['items'];

        // Every returned item must be completed
        foreach ($items as $item) {
            self::assertSame('completed', $item['status']);
        }

        // Our transfer must appear
        $ids = array_column($items, 'id');
        self::assertContains($completed['id'], $ids);

        $this->trackTransfer($completed['id']);
    }

    public function testListTransfersReturns400OnInvalidStatus(): void
    {
        $body = $this->getJson('/transfers', ['status' => 'flying']);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('INVALID_STATUS', $body['error']['code']);
    }

    public function testListTransfersReturns400OnInvalidPerPage(): void
    {
        $body = $this->getJson('/transfers', ['per_page' => 999]);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('INVALID_PER_PAGE', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /transfers/{id}/reverse
    // ──────────────────────────────────────────────────────────────────────────

    public function testReverseTransferReturns200(): void
    {
        // Source needs enough to give back; destination needs enough for reversal debit
        $source = $this->createAccount('Alice', 'USD', 10_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        $transfer = $this->createTransfer($source['id'], $dest['id'], 5_000, 'USD');

        $body = $this->postJson('/transfers/' . $transfer['id'] . '/reverse');

        self::assertSame(200, $this->getStatusCode());
        self::assertSame('reversed', $body['data']['status']);
        self::assertNotNull($body['data']['reversedAt']);
    }

    public function testReverseTransferReturns409OnAlreadyReversed(): void
    {
        $source = $this->createAccount('Alice', 'USD', 10_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        $transfer = $this->createTransfer($source['id'], $dest['id'], 5_000, 'USD');

        // First reversal must succeed
        $this->postJson('/transfers/' . $transfer['id'] . '/reverse');
        self::assertSame(200, $this->getStatusCode());

        // Second reversal must be rejected — already REVERSED
        $body = $this->postJson('/transfers/' . $transfer['id'] . '/reverse');

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('INVALID_TRANSFER_STATE', $body['error']['code']);
    }

    /**
     * A PENDING transfer cannot be reversed — state machine only allows COMPLETED → REVERSED.
     *
     * The handler processes transfers synchronously (PENDING → PROCESSING → COMPLETED
     * all within one HTTP request), making a PENDING state unreachable via the normal
     * HTTP flow. We insert the row directly via DBAL to create this fixture.
     *
     * The FK constraint fk_transfers_source_account (added in
     * Version20260226000001) requires real accounts rows for source_account_id
     * and destination_account_id.  We pre-insert minimal account fixtures and
     * track them for tearDown cleanup.  The handler still checks transfer state
     * (via Transfer::reverse()) BEFORE it loads accounts, so the
     * INVALID_TRANSFER_STATE exception is raised before any account lookup occurs.
     */
    public function testReverseTransferReturns409OnPendingTransfer(): void
    {
        $transferId = '00000000-0000-7000-8000-000000000021';
        $srcId      = '00000000-0000-4000-8000-aaaaaaaaaaaa';
        $dstId      = '00000000-0000-4000-8000-bbbbbbbbbbbb';
        $now        = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);

        // Pre-insert minimal accounts to satisfy fk_transfers_source_account and
        // fk_transfers_destination_account.  INSERT IGNORE is safe on re-runs.
        foreach ([$srcId, $dstId] as $accountId) {
            $conn->executeStatement(
                "INSERT IGNORE INTO accounts
                    (id, owner_name, currency, balance_minor_units, status, version, created_at, updated_at)
                 VALUES (?, 'fixture', 'USD', 0, 'active', 0, ?, ?)",
                [$accountId, $now, $now],
            );
            $this->trackAccount($accountId);
        }

        $conn->executeStatement(
            <<<'SQL'
            INSERT INTO transfers
                (id, source_account_id, destination_account_id,
                 amount_minor_units, currency, status, reference,
                 description, failure_code, failure_reason,
                 completed_at, failed_at, reversed_at, created_at, updated_at)
            VALUES
                (:id, :src, :dst,
                 :amount, :currency, 'pending', :reference,
                 NULL, NULL, NULL,
                 NULL, NULL, NULL, :now, :now)
            SQL,
            [
                'id'        => $transferId,
                'src'       => $srcId,
                'dst'       => $dstId,
                'amount'    => 1_000,
                'currency'  => 'USD',
                'reference' => 'TXN-20260225-000000000021',
                'now'       => $now,
            ],
        );
        $this->trackTransfer($transferId);

        $body = $this->postJson('/transfers/' . $transferId . '/reverse');

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('INVALID_TRANSFER_STATE', $body['error']['code']);
    }

    public function testReverseTransferReturns404OnUnknownTransfer(): void
    {
        $body = $this->postJson('/transfers/00000000-0000-4000-8000-000000000022/reverse');

        self::assertSame(404, $this->getStatusCode());
        self::assertSame('TRANSFER_NOT_FOUND', $body['error']['code']);
    }

    public function testReverseTransferReturns422OnInsufficientFundsAtDestination(): void
    {
        // Source has funds. Destination starts empty — the transfer will credit it.
        // We then drain the destination completely via a second transfer before reversing,
        // so when the reversal tries to debit the destination it has insufficient funds.
        $source = $this->createAccount('Alice', 'USD', 10_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);
        $drain  = $this->createAccount('Charlie', 'USD', 0);

        // Step 1: Transfer 5000 to dest (dest balance = 5000)
        $original = $this->createTransfer($source['id'], $dest['id'], 5_000, 'USD');

        // Step 2: Drain dest completely (dest balance = 0)
        $this->createTransfer($dest['id'], $drain['id'], 5_000, 'USD');

        // Step 3: Attempt reversal — destination (dest) has no funds to be debited
        $body = $this->postJson('/transfers/' . $original['id'] . '/reverse');

        self::assertSame(422, $this->getStatusCode());
        self::assertSame('INSUFFICIENT_FUNDS', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Idempotency (X-Idempotency-Key)
    // ──────────────────────────────────────────────────────────────────────────

    public function testIdempotencyKeyOnInitiateReturnsSameTransferOnRetry(): void
    {
        $source = $this->createAccount('Alice', 'USD', 20_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        $key     = 'idem-test-retry-' . uniqid('', true);
        $payload = [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
        ];

        $this->trackIdempotencyKey($key);

        // First request — creates the transfer
        $first = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $key]);
        self::assertSame(201, $this->getStatusCode());
        $firstId = $first['data']['id'];
        $this->trackTransfer($firstId);

        // Second request — same key, same body — must return the SAME transfer ID
        $second = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $key]);
        self::assertSame(201, $this->getStatusCode());
        $secondId = $second['data']['id'];

        self::assertSame($firstId, $secondId, 'Idempotent retry must return the same transfer ID');

        // Source account must have been debited only once
        $accountBody = $this->getJson('/accounts/' . $source['id']);
        self::assertSame(19_000, $accountBody['data']['balanceMinorUnits']);
    }

    /**
     * Same idempotency key with a different request body must be rejected.
     *
     * This is a client bug — the same key must always map to the same request.
     * IdempotencySubscriber detects the hash mismatch and returns 422 directly
     * (HTTP_UNPROCESSABLE_ENTITY), not 409. The plan document stated 409, but
     * the implementation uses 422 for this case.
     */
    public function testIdempotencyKeyMismatchReturns422(): void
    {
        $source = $this->createAccount('Alice', 'USD', 20_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        $key = 'idem-test-mismatch-' . uniqid('', true);
        $this->trackIdempotencyKey($key);

        // First request — establish the key
        $first = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
        ], ['X-Idempotency-Key' => $key]);

        self::assertSame(201, $this->getStatusCode());
        $this->trackTransfer($first['data']['id']);

        // Second request — same key, DIFFERENT amount → hash mismatch → 422
        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 9_999,   // different body
            'currency'             => 'USD',
        ], ['X-Idempotency-Key' => $key]);

        self::assertSame(422, $this->getStatusCode());
        self::assertSame('IDEMPOTENCY_KEY_REUSE', $body['error']['code']);
    }
}
