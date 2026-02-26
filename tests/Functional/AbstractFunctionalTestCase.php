<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for all HTTP-layer functional tests.
 *
 * ## Architecture
 *   Each test method receives a fresh KernelBrowser (setUp creates it).
 *   Tests explicitly track every resource ID they create; tearDown deletes them
 *   in FK-safe order via a direct DBAL connection to the test database.
 *
 * ## Why NOT transaction-based cleanup
 *   HTTP requests dispatched through KernelBrowser go through the full Symfony
 *   kernel stack. Each request may commit its own DB transaction internally
 *   (e.g. the transfer handler wraps work in transactional()). There is no
 *   single shared transaction we could roll back across multiple HTTP calls.
 *   Explicit DELETE by tracked IDs is the only reliable strategy.
 *
 * ## Rate limiter isolation
 *   config/packages/test/cache.yaml overrides cache.rate_limiter to
 *   cache.adapter.array so each test run starts with a clean bucket.
 *   The bucket resets between test methods because KernelBrowser is
 *   re-created in setUp() which reboots the kernel and its in-memory stores.
 *
 * ## Teardown deletion order (FK-safe)
 *   1. ledger_entries   — references account_id + transfer_id
 *   2. outbox_events    — aggregate_id = account UUID or transfer UUID
 *   3. idempotency_keys — keyed by the raw idempotency key string
 *   4. transfers        — references account UUIDs (source + destination)
 *   5. accounts         — terminal
 *
 * ## Usage
 *   1. Call $this->trackAccount($id) after every POST /accounts.
 *   2. Call $this->trackTransfer($id) after every POST /transfers.
 *   3. Call $this->trackIdempotencyKey($key) whenever a test sends
 *      X-Idempotency-Key — even on 409 responses (the key was stored).
 *   4. Use $this->postJson() / $this->getJson() for HTTP interactions.
 *   5. Use $this->createAccount() / $this->createTransfer() to build
 *      fixture resources that are also auto-tracked for cleanup.
 */
abstract class AbstractFunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    /** @var list<string> Account UUIDs created in this test */
    private array $createdAccountIds = [];

    /** @var list<string> Transfer UUIDs created in this test */
    private array $createdTransferIds = [];

    /** @var list<string> Raw X-Idempotency-Key strings used in this test */
    private array $createdIdempotencyKeys = [];

    // ──────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ──────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // HTTP_HOST avoids "Invalid Host header" warnings in Symfony routing.
        $this->client = static::createClient([], ['HTTP_HOST' => 'localhost']);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tracking helpers — call these after creating resources
    // ──────────────────────────────────────────────────────────────────────────

    protected function trackAccount(string $id): void
    {
        $this->createdAccountIds[] = $id;
    }

    protected function trackTransfer(string $id): void
    {
        $this->createdTransferIds[] = $id;
    }

    /**
     * Track an idempotency key for cleanup.
     *
     * Call this whenever a test sends X-Idempotency-Key — the key is stored in
     * the DB even if the request returns 409 (key-reuse conflict), so it must
     * always be cleaned up.
     */
    protected function trackIdempotencyKey(string $key): void
    {
        $this->createdIdempotencyKeys[] = $key;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HTTP helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * POST with a JSON body; return the decoded response body.
     *
     * Header names use their canonical HTTP form (e.g. 'X-Idempotency-Key').
     * They are automatically converted to the PHP server superglobal format
     * (HTTP_X_IDEMPOTENCY_KEY) before being passed to the browser.
     *
     * For POST /accounts and POST /transfers (the two money-movement / resource-
     * creation endpoints that require X-Idempotency-Key), a unique key is
     * auto-generated and tracked when the caller does not supply one.  This keeps
     * individual tests free from boilerplate while still exercising the real
     * idempotency flow through the full HTTP stack.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function postJson(string $url, array $body = [], array $headers = []): array
    {
        // Auto-supply a unique idempotency key for the two resource-creation
        // endpoints that make it mandatory, unless the caller already provides one.
        if (!isset($headers['X-Idempotency-Key']) && $this->pathRequiresIdempotencyKey($url)) {
            $key = 'test-' . bin2hex(random_bytes(16));
            $headers['X-Idempotency-Key'] = $key;
            $this->trackIdempotencyKey($key);
        }

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            // "X-Idempotency-Key" → "HTTP_X_IDEMPOTENCY_KEY"
            $serverKey          = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$serverKey] = $value;
        }

        $this->client->request(
            method:     'POST',
            uri:        $url,
            parameters: [],
            files:      [],
            server:     $server,
            content:    json_encode($body, JSON_THROW_ON_ERROR),
        );

        return $this->decodeResponse();
    }

    /**
     * Returns true for the two endpoints where X-Idempotency-Key is mandatory.
     */
    private function pathRequiresIdempotencyKey(string $url): bool
    {
        $path = rtrim(parse_url($url, PHP_URL_PATH) ?? $url, '/');

        return $path === '/accounts' || $path === '/transfers';
    }

    /**
     * GET with optional query parameters; return the decoded response body.
     *
     * @param array<string, mixed> $query  Query-string parameters
     * @return array<string, mixed>
     */
    protected function getJson(string $url, array $query = []): array
    {
        $this->client->request(
            method:     'GET',
            uri:        $url,
            parameters: $query,
            files:      [],
            server:     ['HTTP_ACCEPT' => 'application/json'],
        );

        return $this->decodeResponse();
    }

    protected function getStatusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function getResponse(): Response
    {
        return $this->client->getResponse();
    }

    protected function getResponseHeader(string $name): ?string
    {
        return $this->client->getResponse()->headers->get($name);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fixture factories — create resources via HTTP and auto-track them
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Open a new account via POST /accounts and track it for cleanup.
     *
     * @return array<string, mixed> The `data` object from the 201 response
     */
    protected function createAccount(
        string $ownerName      = 'Test Owner',
        string $currency       = 'USD',
        int    $initialBalance = 0,
    ): array {
        $response = $this->postJson('/accounts', [
            'ownerName'                => $ownerName,
            'currency'                 => $currency,
            'initialBalanceMinorUnits' => $initialBalance,
        ]);

        self::assertSame(
            201,
            $this->getStatusCode(),
            sprintf('createAccount() fixture failed: HTTP %d — %s', $this->getStatusCode(), json_encode($response)),
        );

        $data = $response['data'];
        $this->trackAccount($data['id']);

        return $data;
    }

    /**
     * Initiate a transfer via POST /transfers and track it for cleanup.
     *
     * @return array<string, mixed> The `data` object from the 201 response
     */
    protected function createTransfer(
        string $sourceAccountId,
        string $destinationAccountId,
        int    $amountMinorUnits,
        string $currency = 'USD',
    ): array {
        $response = $this->postJson('/transfers', [
            'sourceAccountId'      => $sourceAccountId,
            'destinationAccountId' => $destinationAccountId,
            'amountMinorUnits'     => $amountMinorUnits,
            'currency'             => $currency,
        ]);

        self::assertSame(
            201,
            $this->getStatusCode(),
            sprintf('createTransfer() fixture failed: HTTP %d — %s', $this->getStatusCode(), json_encode($response)),
        );

        $data = $response['data'];
        $this->trackTransfer($data['id']);

        return $data;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function decodeResponse(): array
    {
        $content = $this->client->getResponse()->getContent();

        if ($content === false || $content === '') {
            return [];
        }

        return json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Delete all rows created during this test in FK-safe order.
     *
     * Wrapped in a try/catch so a cleanup failure never masks the actual test
     * failure — cleanup errors are reported as warnings, not test failures.
     */
    private function cleanUp(): void
    {
        // Guard: if the kernel was never booted (setUp() failed before
        // createClient()) there is nothing in the DB to clean up.
        if (!static::$booted) {
            return;
        }

        $hasAccounts    = $this->createdAccountIds !== [];
        $hasTransfers   = $this->createdTransferIds !== [];
        $hasIdempotency = $this->createdIdempotencyKeys !== [];

        if (!$hasAccounts && !$hasTransfers && !$hasIdempotency) {
            return;
        }

        try {
            /** @var Connection $conn */
            $conn = static::getContainer()->get(Connection::class);

            // 1. ledger_entries — keyed by account_id
            if ($hasAccounts) {
                $placeholders = $this->placeholders($this->createdAccountIds);
                $conn->executeStatement(
                    "DELETE FROM ledger_entries WHERE account_id IN ({$placeholders})",
                    $this->createdAccountIds,
                );
            }

            // 2. outbox_events — aggregate_id is any tracked UUID
            $allIds = array_merge($this->createdAccountIds, $this->createdTransferIds);
            if ($allIds !== []) {
                $placeholders = $this->placeholders($allIds);
                $conn->executeStatement(
                    "DELETE FROM outbox_events WHERE aggregate_id IN ({$placeholders})",
                    $allIds,
                );
            }

            // 3. idempotency_keys — keyed by raw key string
            if ($hasIdempotency) {
                $placeholders = $this->placeholders($this->createdIdempotencyKeys);
                $conn->executeStatement(
                    "DELETE FROM idempotency_keys WHERE idempotency_key IN ({$placeholders})",
                    $this->createdIdempotencyKeys,
                );
            }

            // 4. transfers — must come after ledger_entries (no FK but correct semantics)
            if ($hasTransfers) {
                $placeholders = $this->placeholders($this->createdTransferIds);
                $conn->executeStatement(
                    "DELETE FROM transfers WHERE id IN ({$placeholders})",
                    $this->createdTransferIds,
                );
            }

            // 5. accounts — terminal (nothing references them after above deletes)
            if ($hasAccounts) {
                $placeholders = $this->placeholders($this->createdAccountIds);
                $conn->executeStatement(
                    "DELETE FROM accounts WHERE id IN ({$placeholders})",
                    $this->createdAccountIds,
                );
            }
        } catch (\Throwable $e) {
            // Report cleanup failures as a warning so they're visible but don't
            // override the test result (pass/fail is determined before tearDown).
            trigger_error(
                sprintf(
                    '[AbstractFunctionalTestCase] cleanup failed: %s — %s',
                    $e::class,
                    $e->getMessage(),
                ),
                E_USER_WARNING,
            );
        } finally {
            $this->createdAccountIds     = [];
            $this->createdTransferIds    = [];
            $this->createdIdempotencyKeys = [];
        }
    }

    /**
     * Build a comma-separated list of '?' placeholders for a DBAL IN() clause.
     *
     * @param list<string> $items
     */
    private function placeholders(array $items): string
    {
        return implode(',', array_fill(0, count($items), '?'));
    }
}
