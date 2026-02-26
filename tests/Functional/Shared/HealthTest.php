<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\AbstractFunctionalTestCase;

/**
 * Functional tests for GET /health.
 *
 * The health endpoint returns a flat JSON object (no { "data": ... } wrapper):
 *   { "status": "ok"|"degraded", "checks": { "database": "ok", ... }, "timestamp": "..." }
 */
final class HealthTest extends AbstractFunctionalTestCase
{
    /**
     * Health endpoint responds with 200 (ok) or 503 (degraded).
     * In the test environment the outbox processor is not running, so
     * stuck outbox events may cause a degraded response — both are valid.
     */
    public function testHealthResponds(): void
    {
        $this->getJson('/health');

        self::assertContains(
            $this->getStatusCode(),
            [200, 503],
            'Health endpoint must return 200 (ok) or 503 (degraded)',
        );
    }

    public function testHealthResponseEnvelopeIsCorrect(): void
    {
        $body = $this->getJson('/health');

        self::assertContains($this->getStatusCode(), [200, 503]);

        self::assertArrayHasKey('status',    $body, '"status" key must be present');
        self::assertArrayHasKey('checks',    $body, '"checks" key must be present');
        self::assertArrayHasKey('timestamp', $body, '"timestamp" key must be present');

        self::assertContains(
            $body['status'],
            ['ok', 'degraded'],
            '"status" must be "ok" or "degraded"',
        );

        self::assertIsArray($body['checks'], '"checks" must be an object/array');
        self::assertNotEmpty($body['timestamp'], '"timestamp" must not be empty');
    }

    public function testHealthReportsDbConnected(): void
    {
        $body = $this->getJson('/health');

        self::assertContains($this->getStatusCode(), [200, 503]);
        self::assertSame(
            'ok',
            $body['checks']['database'] ?? null,
            'Database check must report "ok" in the test environment',
        );
    }
}
