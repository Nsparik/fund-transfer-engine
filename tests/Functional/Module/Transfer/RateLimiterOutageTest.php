<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Transfer;

use App\Tests\Functional\AbstractFunctionalTestCase;
use App\Tests\Support\ThrowingCacheAdapter;

/**
 * TC-5 — Rate Limiter Outage Behaviour
 *
 * Verifies that when the Redis-backed rate limiter pool is unavailable:
 *   a) The transfer request still succeeds with HTTP 201 (fail-open).
 *   b) No HTTP 500 is returned (the \RedisException is caught and handled).
 *   c) The transfer is persisted correctly in the DB.
 *
 * ## "Real outage" simulation
 *   The test replaces the `cache.rate_limiter` service in the test container
 *   with a ThrowingCacheAdapter — a concrete PHP class that throws
 *   \RedisException on every cache operation.  This is not a PHPUnit mock:
 *   it is a real implementation that exercises the same exception path that a
 *   Redis connection failure would trigger in production.
 *
 *   The full call chain exercised:
 *     TransferController::initiate()
 *       → RateLimiterFactory::create()
 *       → SlidingWindowLimiter::consume()
 *       → CacheStorage::fetch()               ← calls ThrowingCacheAdapter::getItem()
 *       → \RedisException thrown
 *     catch (\Throwable $rateLimiterError):
 *       → logger->warning('rate_limiter.unavailable', [...])
 *       → request allowed through
 *
 * ## What this test does NOT check
 *   - The warning log content (verifying log output in Symfony functional
 *     tests requires the profiler data collector, which adds setup overhead).
 *     The rate_limiter.unavailable log is verified via unit test in
 *     TransferControllerTest once added.  The HTTP-level behaviour (201, not
 *     500) is the critical financial safety assertion.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Functional
 */
final class RateLimiterOutageTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent KernelBrowser from shutting-down and re-booting the kernel
        // between requests.  Without this, the kernel reboots after each request
        // (starting from the second), creating a fresh container that no longer
        // contains the ThrowingCacheAdapter override.  Disabling reboot ensures
        // the adapter persists across all requests in the test method — identical
        // to the approach used in RateLimitingTest.
        //
        // ThrowingCacheAdapter::reset() (inherited from ArrayAdapter) calls clear()
        // on an empty store — safe under services_resetter, no data to wipe.
        $this->client->disableReboot();

        // Replace the cache pool that backs the rate limiter with one that
        // always throws \RedisException.  The container service is declared
        // public: true in config/services_test.yaml so set() works here.
        static::getContainer()->set('cache.rate_limiter', new ThrowingCacheAdapter());
    }

    public function testTransferSucceedsWhenRateLimiterThrows(): void
    {
        $source = $this->createAccount('Alice', 'USD', 50_000);
        $dest   = $this->createAccount('Bob', 'USD', 0);

        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $source['id'],
            'destinationAccountId' => $dest['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
            'description'          => 'TC-5: rate limiter outage',
        ]);

        // ── Assert: HTTP 201, not 500 ─────────────────────────────────────────
        self::assertSame(
            201,
            $this->getStatusCode(),
            sprintf(
                'Transfer must succeed (201) even when rate limiter throws. Got %d: %s',
                $this->getStatusCode(),
                json_encode($body),
            ),
        );

        // ── Assert: Transfer was persisted with COMPLETED status ───────────────
        self::assertArrayHasKey('data', $body, 'Response must have data envelope');
        self::assertSame('completed', $body['data']['status'], 'Transfer must reach COMPLETED status');
        self::assertNotEmpty($body['data']['id'], 'Transfer ID must be present');

        $this->trackTransfer($body['data']['id']);

        // ── Assert: Location header is set ────────────────────────────────────
        self::assertSame(
            '/transfers/' . $body['data']['id'],
            $this->getResponseHeader('Location'),
            'Location header must point to the created transfer',
        );
    }

    public function testRateLimiterOutageDoesNotAffectMultipleTransfers(): void
    {
        // Verify that multiple transfers in sequence still all succeed when
        // the rate limiter is permanently broken.
        $source = $this->createAccount('Sender', 'USD', 100_000);
        $dest   = $this->createAccount('Receiver', 'USD', 0);

        for ($i = 0; $i < 3; $i++) {
            $body = $this->postJson('/transfers', [
                'sourceAccountId'      => $source['id'],
                'destinationAccountId' => $dest['id'],
                'amountMinorUnits'     => 100,
                'currency'             => 'USD',
            ]);

            self::assertSame(
                201,
                $this->getStatusCode(),
                "Transfer #{$i} must succeed despite broken rate limiter",
            );

            $this->trackTransfer($body['data']['id']);
        }
    }

    public function testBrokenRateLimiterDoesNotAffectValidationErrors(): void
    {
        // Even with a broken rate limiter, validation errors must still be
        // returned correctly (400) — the fail-open code runs before validation.
        $body = $this->postJson('/transfers', []);

        self::assertSame(400, $this->getStatusCode(), 'Validation error must still return 400');
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
    }
}
