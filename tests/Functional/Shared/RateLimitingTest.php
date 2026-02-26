<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Functional tests for the POST /transfers rate limiter.
 *
 * ## Environment
 *   config/packages/test/cache.yaml overrides cache.rate_limiter to an
 *   in-memory ArrayAdapter.  The kernel is rebooted each setUp(), so every
 *   test method starts with a completely fresh rate-limit bucket.
 *
 * ## Why we pre-exhaust via direct service calls (not HTTP loops)
 *   Symfony's Kernel::boot() calls services_resetter->reset() before every
 *   request starting from the second one (Kernel::$resetServices is set to
 *   true after each Kernel::handle() call).  services_resetter resets the
 *   cache.rate_limiter ArrayAdapter, wiping all stored sliding-window data.
 *
 *   To work around this, we exhaust the rate-limit bucket by calling
 *   RateLimiterFactory::create($ip)->consume(N) directly — no HTTP request,
 *   no Kernel::handle(), so services_resetter is never triggered.  We then
 *   make a single HTTP request that will observe the pre-exhausted state
 *   (services_resetter does not fire before the very first handle() call in
 *   each test because resetServices starts as false after kernel boot).
 *
 * ## IP simulation
 *   The rate limiter keys on $request->getClientIp() which reads REMOTE_ADDR.
 *   We set REMOTE_ADDR via setServerParameter() on the KernelBrowser.
 *
 * ## Limit: 10 req / minute per IP (sliding window)
 */
final class RateLimitingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent KernelBrowser from shutting-down and re-booting the kernel
        // between requests.  Without this every request after the first creates
        // a new container — and a new PersistentArrayAdapter instance — wiping
        // all accumulated rate-limit state.
        $this->client->disableReboot();

        // Explicitly fix the REMOTE_ADDR so rate-limiter key derivation is stable.
        $this->client->setServerParameter('REMOTE_ADDR', '127.0.0.1');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Exhaust all 10 tokens in the rate-limiter bucket for $ip via a direct
     * service call.  Does NOT go through Kernel::handle() so services_resetter
     * cannot wipe the ArrayAdapter state.
     */
    private function exhaustRateLimitForIp(string $ip): void
    {
        /** @var RateLimiterFactory $factory */
        $factory = static::getContainer()->get('limiter.transfer_creation');
        $factory->create($ip)->consume(10);
    }

    // ── Test 1 ────────────────────────────────────────────────────────────────

    /**
     * Verify that a fresh bucket allows at least 1 transfer to go through.
     * Each KernelBrowser request resets the ArrayAdapter (services_resetter),
     * so we verify the per-request behaviour: every POST within the limit
     * returns 201.
     */
    public function test10ConsecutivePostTransfersAllSucceed(): void
    {
        $src     = $this->createAccount('Sender', 'USD', 100_000);
        $dst     = $this->createAccount('Receiver', 'USD', 0);
        $payload = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 100,
            'currency'             => 'USD',
        ];

        for ($i = 1; $i <= 10; $i++) {
            $resp = $this->postJson('/transfers', $payload);
            self::assertSame(
                201,
                $this->getStatusCode(),
                "Request #{$i} should succeed (201) — got {$this->getStatusCode()}",
            );
            $this->trackTransfer($resp['data']['id']);
        }
    }

    // ── Test 2 ────────────────────────────────────────────────────────────────

    /**
     * When all 10 tokens are consumed, the next HTTP request must return 429.
     *
     * We pre-exhaust the bucket via a direct RateLimiterFactory call so that
     * the first (and only) HTTP request in this test sees a full bucket used up.
     */
    public function test11thRequestReturns429(): void
    {
        $src     = $this->createAccount('Sender', 'USD', 100_000);
        $dst     = $this->createAccount('Receiver', 'USD', 0);
        $payload = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 100,
            'currency'             => 'USD',
        ];

        // Pre-exhaust all 10 tokens — no HTTP, so services_resetter is not triggered.
        $this->exhaustRateLimitForIp('127.0.0.1');

        // First HTTP request; resetServices = false → no reset fires → bucket exhausted → 429.
        $this->postJson('/transfers', $payload);
        self::assertSame(429, $this->getStatusCode());
    }

    // ── Test 3 ────────────────────────────────────────────────────────────────

    /**
     * A 429 response must carry a Retry-After header with a positive value.
     */
    public function test429ResponseHasRetryAfterHeader(): void
    {
        $src     = $this->createAccount('Sender', 'USD', 100_000);
        $dst     = $this->createAccount('Receiver', 'USD', 0);
        $payload = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 100,
            'currency'             => 'USD',
        ];

        $this->exhaustRateLimitForIp('127.0.0.1');
        $this->postJson('/transfers', $payload);

        self::assertSame(429, $this->getStatusCode());

        $retryAfter = $this->getResponseHeader('Retry-After');
        self::assertNotNull($retryAfter, 'Retry-After header must be present on 429 response');
        self::assertGreaterThan(0, (int) $retryAfter, 'Retry-After must be a positive number of seconds');
    }

    // ── Test 4 ────────────────────────────────────────────────────────────────

    /**
     * A 429 response must carry X-RateLimit-Remaining: 0.
     */
    public function test429ResponseHasXRateLimitRemainingZero(): void
    {
        $src     = $this->createAccount('Sender', 'USD', 100_000);
        $dst     = $this->createAccount('Receiver', 'USD', 0);
        $payload = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 100,
            'currency'             => 'USD',
        ];

        $this->exhaustRateLimitForIp('127.0.0.1');
        $this->postJson('/transfers', $payload);

        self::assertSame(429, $this->getStatusCode());
        self::assertSame('0', $this->getResponseHeader('X-RateLimit-Remaining'));
    }

    // ── Test 5 ────────────────────────────────────────────────────────────────

    /**
     * The rate limiter keys on client IP — exhausting IP A must not affect IP B.
     *
     * Verification strategy:
     *   1. Exhaust IP A's 10-token bucket via direct service calls (no HTTP).
     *   2. Confirm IP B's bucket is independent by directly calling consume(1)
     *      on IP B's limiter — it must be accepted.
     *   3. Make the first (and only) HTTP request from IP A — no services_resetter
     *      wipe occurs before the first handle() call, so IP A's exhausted state
     *      is intact and the controller returns 429.
     */
    public function testRateLimitAppliesPerIp(): void
    {
        $src     = $this->createAccount('Sender', 'USD', 100_000);
        $dst     = $this->createAccount('Receiver', 'USD', 0);
        $payload = [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 100,
            'currency'             => 'USD',
        ];

        // 1. Exhaust IP A only — direct service call, no HTTP.
        $this->exhaustRateLimitForIp('127.0.0.1');

        // 2. IP A — bucket exhausted → 429.
        //    PersistentArrayAdapter's no-op reset() keeps the state intact
        //    across the services_resetter call that precedes this request.
        $this->client->setServerParameter('REMOTE_ADDR', '127.0.0.1');
        $this->postJson('/transfers', $payload);
        self::assertSame(429, $this->getStatusCode(), 'IP A must be rate-limited after exhausting its bucket');

        // 3. IP B — independent bucket, never touched → 201.
        $this->client->setServerParameter('REMOTE_ADDR', '10.0.0.2');
        $resp = $this->postJson('/transfers', $payload);
        self::assertSame(201, $this->getStatusCode(), 'IP B must not be affected by IP A exhausting its bucket');
        $this->trackTransfer($resp['data']['id']);
    }
}
