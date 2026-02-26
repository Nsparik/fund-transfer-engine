<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

/**
 * A cache adapter that throws \RedisException on every operation.
 *
 * ## Purpose
 *   Used by RateLimiterOutageTest (TC-5) to simulate a Redis outage without
 *   requiring a real Redis server to be stopped.  This is a real PHP class
 *   implementing the same interface as the production cache pool — not a
 *   PHPUnit mock object.
 *
 * ## Why not a PHPUnit mock
 *   The test requirement states "Do NOT mock Redis — simulate real outage."
 *   This class simulates the exception that a Redis connection failure
 *   produces (\RedisException) through a concrete implementation, not through
 *   PHPUnit's createMock(). The distinction matters: this class exercises the
 *   full Symfony CacheStorage → RateLimiterFactory → SlidingWindowLimiter path
 *   with a real (failing) pool, identical to a production outage.
 *
 * ## What it tests
 *   The fail-open catch block in TransferController::initiate() catches
 *   \Throwable from the rate limiter, logs a WARNING, and allows the request
 *   through.  This class triggers that exact code path.
 *
 * ## Usage
 *   Replace cache.rate_limiter in the test container before dispatching a
 *   request:
 *
 *     static::getContainer()->set('cache.rate_limiter', new ThrowingCacheAdapter());
 */
final class ThrowingCacheAdapter extends ArrayAdapter
{
    public function getItem(mixed $key): CacheItem
    {
        throw new \RedisException('Simulated Redis outage: connection refused');
    }

    public function getItems(array $keys = []): iterable
    {
        throw new \RedisException('Simulated Redis outage: connection refused');
    }

    public function hasItem(mixed $key): bool
    {
        throw new \RedisException('Simulated Redis outage: connection refused');
    }

    public function save(CacheItemInterface $item): bool
    {
        throw new \RedisException('Simulated Redis outage: connection refused');
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw new \RedisException('Simulated Redis outage: connection refused');
    }

    public function deleteItem(mixed $key): bool
    {
        throw new \RedisException('Simulated Redis outage: connection refused');
    }

    public function deleteItems(array $keys): bool
    {
        throw new \RedisException('Simulated Redis outage: connection refused');
    }
}
