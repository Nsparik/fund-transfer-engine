<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\AbstractFunctionalTestCase;

/**
 * Functional tests for the X-Idempotency-Key cross-cutting concern.
 *
 * Scope: IdempotencySubscriber applies to all POST /transfers* and POST /accounts* routes.
 * The hash stored with each key covers (HTTP method + path + body), so:
 *   - Same key + same body + same path → cached response (no second execution)
 *   - Same key + different body/path  → 422 IDEMPOTENCY_KEY_REUSE
 *   - Different keys + same body      → two independent resources
 *   - No key on POST /transfers or POST /accounts → 400 IDEMPOTENCY_KEY_REQUIRED
 */
final class IdempotencyTest extends AbstractFunctionalTestCase
{
    // ── Test 1: cache hit on retry ────────────────────────────────────────────

    /**
     * Replaying the exact same request with the same key must:
     *   a) Return HTTP 201 with identical data
     *   b) Not double-execute the handler (only 1 ledger debit, not 2)
     */
    public function testIdempotencyKeyReturnsCachedResponseOnRetry(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);
        $key = 'idempotency-test-retry-' . uniqid('', true);
        $this->trackIdempotencyKey($key);

        $payload = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
        ];

        // First request — handler runs, transfer created, ledger entries written
        $first = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $key]);
        self::assertSame(201, $this->getStatusCode(), 'First request must return 201');
        $this->trackTransfer($first['data']['id']);

        // Second request — cached; handler must NOT run again
        $second = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $key]);
        self::assertSame(201, $this->getStatusCode(), 'Replay must also return 201');

        // Both responses must reference the exact same transfer
        self::assertSame($first['data']['id'], $second['data']['id'], 'Replay must return the same transfer ID');

        // Verify only 1 debit entry exists (not 2) — handler was not invoked twice
        $range = [
            'from' => (new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'to'   => (new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];
        $statement = $this->getJson("/accounts/{$src['id']}/statement", $range);
        $transferMovements = array_filter($statement['data']['movements'], static fn ($m) => $m['transferType'] === 'transfer');
        self::assertSame(1, count($transferMovements), 'Exactly 1 debit entry must exist — no duplication');
    }

    // ── Test 2: hash mismatch (different body) ────────────────────────────────

    /**
     * Sending a different payload with the same idempotency key must be
     * rejected with 422 IDEMPOTENCY_KEY_REUSE — guards against client bugs.
     */
    public function testDifferentBodyWithSameKeyReturns422(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);
        $key = 'idempotency-test-mismatch-' . uniqid('', true);
        $this->trackIdempotencyKey($key);

        // First request — succeeds, key stored with hash of this body
        $first = $this->postJson('/transfers', [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 500,
            'currency'             => 'USD',
        ], ['X-Idempotency-Key' => $key]);
        self::assertSame(201, $this->getStatusCode());
        $this->trackTransfer($first['data']['id']);

        // Second request — same key, DIFFERENT amount → hash mismatch
        $second = $this->postJson('/transfers', [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 999, // different
            'currency'             => 'USD',
        ], ['X-Idempotency-Key' => $key]);

        self::assertSame(422, $this->getStatusCode());
        self::assertSame('IDEMPOTENCY_KEY_REUSE', $second['error']['code']);
    }

    // ── Test 3: different keys → distinct resources ───────────────────────────

    public function testSameBodyDifferentKeyCreatesNewResource(): void
    {
        $src = $this->createAccount('Sender', 'USD', 10_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        $key1 = 'idempotency-test-key1-' . uniqid('', true);
        $key2 = 'idempotency-test-key2-' . uniqid('', true);
        $this->trackIdempotencyKey($key1);
        $this->trackIdempotencyKey($key2);

        $payload = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 100,
            'currency'             => 'USD',
        ];

        $resp1 = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $key1]);
        self::assertSame(201, $this->getStatusCode());
        $this->trackTransfer($resp1['data']['id']);

        $resp2 = $this->postJson('/transfers', $payload, ['X-Idempotency-Key' => $key2]);
        self::assertSame(201, $this->getStatusCode());
        $this->trackTransfer($resp2['data']['id']);

        self::assertNotSame(
            $resp1['data']['id'],
            $resp2['data']['id'],
            'Different keys must produce distinct transfer resources',
        );
    }

    // ── Test 4: missing key is rejected ──────────────────────────────────────

    /**
     * POST /transfers without X-Idempotency-Key must be rejected with
     * 400 IDEMPOTENCY_KEY_REQUIRED.  The client must always supply a stable
     * key so that retries after network timeouts are safe and do not produce
     * duplicate money movements.
     */
    public function testRequestWithoutKeyReturns400(): void
    {
        $src = $this->createAccount('Sender', 'USD', 5_000);
        $dst = $this->createAccount('Receiver', 'USD', 0);

        // Use client->request() directly so no auto-key is injected by postJson().
        $this->client->request(
            'POST',
            '/transfers',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'sourceAccountId'      => $src['id'],
                'destinationAccountId' => $dst['id'],
                'amountMinorUnits'     => 500,
                'currency'             => 'USD',
            ]),
        );

        self::assertSame(400, $this->getStatusCode());
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('IDEMPOTENCY_KEY_REQUIRED', $body['error']['code']);
    }

    // ── Test 5: idempotency on the reverse endpoint ───────────────────────────

    /**
     * POST /transfers/{id}/reverse is also a POST /transfers* route, so
     * IdempotencySubscriber applies. A second reverse with the same key must
     * return the cached 200 response instead of failing with 409
     * INVALID_TRANSFER_STATE (the transfer is already REVERSED).
     */
    public function testIdempotencyAppliesToReverseEndpoint(): void
    {
        $src      = $this->createAccount('Sender', 'USD', 10_000);
        $dst      = $this->createAccount('Receiver', 'USD', 0);
        $transfer = $this->createTransfer($src['id'], $dst['id'], 2_000);
        $key      = 'idempotency-test-reverse-' . uniqid('', true);
        $this->trackIdempotencyKey($key);

        $reverseUrl = '/transfers/' . $transfer['id'] . '/reverse';

        // First reversal — succeeds, COMPLETED → REVERSED
        $first = $this->postJson($reverseUrl, [], ['X-Idempotency-Key' => $key]);
        self::assertSame(200, $this->getStatusCode(), 'First reversal must return 200');
        self::assertSame('reversed', $first['data']['status']);

        // Second request with same key — cached; transfer is already REVERSED but
        // IdempotencySubscriber short-circuits before the handler, returning the
        // same 200 response without invoking ReverseTransferHandler again.
        $second = $this->postJson($reverseUrl, [], ['X-Idempotency-Key' => $key]);
        self::assertSame(200, $this->getStatusCode(), 'Idempotent replay must return 200, not 409');
        self::assertSame($first['data']['id'], $second['data']['id']);
    }

    // ── Test 6: cross-path hash prevents key reuse across endpoints ───────────

    /**
     * The hash stored with each key covers (method + path + body).
     * Using key K on POST /transfers creates a hash with path '/transfers'.
     * Using key K on POST /transfers/{id}/reverse has a different path →
     * different hash → IdempotencySubscriber returns 422 IDEMPOTENCY_KEY_REUSE.
     *
     * This is a protection against accidental key reuse by the caller — the
     * key namespace is implicitly scoped to the (method + path) tuple.
     */
    public function testSameKeyOnDifferentEndpointTriggersReuseError(): void
    {
        $src      = $this->createAccount('Sender', 'USD', 10_000);
        $dst      = $this->createAccount('Receiver', 'USD', 0);
        $transfer = $this->createTransfer($src['id'], $dst['id'], 1_000);
        $key      = 'idempotency-test-cross-path-' . uniqid('', true);
        $this->trackIdempotencyKey($key);

        // First request: POST /transfers with key K → 201, key+hash stored
        $body = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 500,
            'currency'             => 'USD',
        ];
        $first = $this->postJson('/transfers', $body, ['X-Idempotency-Key' => $key]);
        self::assertSame(201, $this->getStatusCode());
        $this->trackTransfer($first['data']['id']);

        // Second request: POST /transfers/{id}/reverse with same key K.
        // Path differs → hash(POST|/transfers/{id}/reverse|) ≠ stored hash
        // → IdempotencySubscriber returns 422 IDEMPOTENCY_KEY_REUSE.
        $conflict = $this->postJson('/transfers/' . $transfer['id'] . '/reverse', [], ['X-Idempotency-Key' => $key]);
        self::assertSame(422, $this->getStatusCode());
        self::assertSame('IDEMPOTENCY_KEY_REUSE', $conflict['error']['code']);
    }
}
