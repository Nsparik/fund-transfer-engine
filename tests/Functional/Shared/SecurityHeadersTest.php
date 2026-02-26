<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\AbstractFunctionalTestCase;

/**
 * Verifies that SecurityHeadersSubscriber appends all 9 security headers
 * to every HTTP response, regardless of route or status code.
 *
 * Uses GET /health as a lightweight probe (no DB rows created, no tearDown
 * tracking needed). Also checks 404 responses to prove headers fire on
 * error paths.
 */
final class SecurityHeadersTest extends AbstractFunctionalTestCase
{
    /** All 9 headers emitted by SecurityHeadersSubscriber */
    private const ALL_HEADERS = [
        'X-Content-Type-Options',
        'X-Frame-Options',
        'X-XSS-Protection',
        'Referrer-Policy',
        'Content-Security-Policy',
        'Cache-Control',
        'Pragma',
        'Strict-Transport-Security',
        'Permissions-Policy',
    ];

    // ── Test 1: presence of all headers ──────────────────────────────────────

    public function testAllSecurityHeadersPresentOnApiResponse(): void
    {
        $this->getJson('/health');

        foreach (self::ALL_HEADERS as $header) {
            self::assertNotNull(
                $this->getResponseHeader($header),
                "Expected security header '{$header}' to be present on GET /health response",
            );
        }
    }

    // ── Tests 2–7: specific header values ─────────────────────────────────────

    public function testXContentTypeOptionsIsNosniff(): void
    {
        $this->getJson('/health');

        self::assertSame('nosniff', $this->getResponseHeader('X-Content-Type-Options'));
    }

    public function testXFrameOptionsIsDeny(): void
    {
        $this->getJson('/health');

        self::assertSame('DENY', $this->getResponseHeader('X-Frame-Options'));
    }

    public function testHstsHasMinimumMaxAgeAndIncludeSubDomains(): void
    {
        $this->getJson('/health');

        $hsts = $this->getResponseHeader('Strict-Transport-Security') ?? '';

        self::assertStringContainsString('max-age=31536000', $hsts);
        self::assertStringContainsString('includeSubDomains', $hsts);
    }

    public function testCspRestrictsDefaultSrc(): void
    {
        $this->getJson('/health');

        $csp = $this->getResponseHeader('Content-Security-Policy') ?? '';

        self::assertStringContainsString("default-src 'none'", $csp);
    }

    public function testPermissionsPolicyDisablesCameraAndMicrophone(): void
    {
        $this->getJson('/health');

        $policy = $this->getResponseHeader('Permissions-Policy') ?? '';

        self::assertStringContainsString('camera=()', $policy);
        self::assertStringContainsString('microphone=()', $policy);
    }

    public function testCacheControlIsNoStore(): void
    {
        $this->getJson('/health');

        $cc = $this->getResponseHeader('Cache-Control') ?? '';

        self::assertStringContainsString('no-store', $cc);
    }

    // ── Test 8: headers present on error (404) responses ─────────────────────

    public function testHeadersPresentOn404Responses(): void
    {
        // This UUID does not exist — response will be 404 from AccountNotFoundException
        $this->getJson('/accounts/00000000-0000-4000-8000-000000000000');

        self::assertSame(404, $this->getStatusCode());
        self::assertNotNull(
            $this->getResponseHeader('X-Frame-Options'),
            'Security headers must be present even on 404 responses',
        );
    }
}
