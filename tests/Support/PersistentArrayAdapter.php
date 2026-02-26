<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * ArrayAdapter that ignores reset() calls.
 *
 * ## Problem
 * When KernelBrowser dispatches multiple HTTP requests in the same test
 * (with kernel reboot disabled via KernelBrowser::disableReboot()), Symfony's
 * services_resetter calls reset() on every service tagged with kernel.reset —
 * including all cache pools — before each request starting from the second.
 *
 * A standard ArrayAdapter honours reset() by clearing its in-memory store,
 * wiping all sliding-window data stored by the rate limiter between requests.
 * This makes it impossible to accumulate rate-limit hits across requests in a
 * single test method.
 *
 * ## Solution
 * Override reset() as a no-op so the cache pool retains its contents across
 * intra-test requests.  A fresh kernel (and therefore a fresh instance of this
 * class) is constructed for every test method via setUp()/createClient(), so
 * there is no cross-test contamination.
 *
 * ## Usage
 * Configured as the backing service for cache.rate_limiter in
 * config/services_test.yaml, which overrides the framework cache-pool
 * definition in config/packages/test/cache.yaml.
 * RateLimitingTest::setUp() calls $this->client->disableReboot() so that the
 * same kernel instance is shared for all requests within one test method.
 */
final class PersistentArrayAdapter extends ArrayAdapter
{
    /**
     * Intentionally a no-op.
     *
     * Prevents services_resetter from clearing rate-limit sliding-window data
     * between consecutive HTTP requests within the same test method.
     */
    public function reset(): void
    {
        // no-op — see class-level docblock
    }
}
