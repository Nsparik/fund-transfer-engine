<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\UI\Http;

use App\Shared\UI\Http\CorrelationIdSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for CorrelationIdSubscriber.
 *
 * Covers:
 *  - onRequest: passes through an existing X-Correlation-ID header
 *  - onRequest: generates a UUID v4 when no header is present
 *  - onRequest: only runs for main requests (sub-requests are skipped)
 *  - onResponse: appends X-Correlation-ID to response header
 *  - onResponse: does nothing when correlation_id attribute is absent
 *  - Subscribed events are REQUEST (priority 100) and RESPONSE (priority -100)
 */
final class CorrelationIdSubscriberTest extends TestCase
{
    private CorrelationIdSubscriber $subscriber;
    private HttpKernelInterface     $kernel;

    protected function setUp(): void
    {
        $this->subscriber = new CorrelationIdSubscriber();
        $this->kernel     = $this->createStub(HttpKernelInterface::class);
    }

    // ── Subscribed events ─────────────────────────────────────────────────────

    public function testSubscribedEventsContainRequestAndResponse(): void
    {
        $events = CorrelationIdSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function testRequestListenerHasPriority100(): void
    {
        $events = CorrelationIdSubscriber::getSubscribedEvents();

        self::assertSame(100, $events[KernelEvents::REQUEST][1]);
    }

    public function testResponseListenerHasPriorityMinus100(): void
    {
        $events = CorrelationIdSubscriber::getSubscribedEvents();

        self::assertSame(-100, $events[KernelEvents::RESPONSE][1]);
    }

    // ── onRequest: existing header ────────────────────────────────────────────

    public function testExistingCorrelationIdHeaderIsPreserved(): void
    {
        $existingId = 'my-existing-correlation-id-12345';
        $request    = Request::create('/test');
        $request->headers->set('X-Correlation-ID', $existingId);

        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onRequest($event);

        self::assertSame($existingId, $request->attributes->get('correlation_id'));
    }

    // ── onRequest: no header — generate UUID ─────────────────────────────────

    public function testMissingCorrelationIdHeaderGeneratesUuid(): void
    {
        $request = Request::create('/test');
        $event   = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onRequest($event);

        $correlationId = $request->attributes->get('correlation_id');

        self::assertNotNull($correlationId);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $correlationId,
            'Generated correlation ID must be a valid UUID v4',
        );
    }

    public function testEachRequestGeneratesADifferentUuid(): void
    {
        $request1 = Request::create('/test');
        $request2 = Request::create('/test');

        $this->subscriber->onRequest(
            new RequestEvent($this->kernel, $request1, HttpKernelInterface::MAIN_REQUEST)
        );
        $this->subscriber->onRequest(
            new RequestEvent($this->kernel, $request2, HttpKernelInterface::MAIN_REQUEST)
        );

        self::assertNotSame(
            $request1->attributes->get('correlation_id'),
            $request2->attributes->get('correlation_id'),
        );
    }

    // ── onRequest: sub-requests are skipped ───────────────────────────────────

    public function testSubRequestDoesNotSetCorrelationId(): void
    {
        $request = Request::create('/test');
        $event   = new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->subscriber->onRequest($event);

        self::assertNull($request->attributes->get('correlation_id'));
    }

    // ── onResponse: appends header ────────────────────────────────────────────

    public function testResponseIncludesCorrelationIdHeader(): void
    {
        $id      = 'test-correlation-id-xyz';
        $request = Request::create('/test');
        $request->attributes->set('correlation_id', $id);

        $response = new Response();
        $event    = new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onResponse($event);

        self::assertSame($id, $response->headers->get('X-Correlation-ID'));
    }

    public function testResponseDoesNotSetHeaderWhenAttributeAbsent(): void
    {
        $request  = Request::create('/test');
        $response = new Response();
        $event    = new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onResponse($event);

        self::assertFalse($response->headers->has('X-Correlation-ID'));
    }

    public function testSubResponseIsSkipped(): void
    {
        $request = Request::create('/test');
        $request->attributes->set('correlation_id', 'should-not-appear');

        $response = new Response();
        $event    = new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::SUB_REQUEST,
            $response,
        );

        $this->subscriber->onResponse($event);

        self::assertFalse($response->headers->has('X-Correlation-ID'));
    }

    // ── Round-trip: request→response ─────────────────────────────────────────

    public function testCorrelationIdRoundTrip(): void
    {
        $request = Request::create('/api/transfers');

        $requestEvent = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onRequest($requestEvent);

        $correlationId = $request->attributes->get('correlation_id');
        self::assertNotNull($correlationId);

        $response      = new Response();
        $responseEvent = new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $this->subscriber->onResponse($responseEvent);

        self::assertSame($correlationId, $response->headers->get('X-Correlation-ID'));
    }

    // ── Input sanitization ────────────────────────────────────────────────────

    public function testCrlfCharactersAreStrippedFromIncomingCorrelationId(): void
    {
        // CRLF injection attack: attacker tries to inject an extra response header
        // by embedding \r\n in the correlation ID value.
        $malicious = "legit-id\r\nX-Injected: evil";
        $request   = Request::create('/test');
        $request->headers->set('X-Correlation-ID', $malicious);

        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onRequest($event);

        $stored = (string) $request->attributes->get('correlation_id');

        // The CRLF characters that would split a response header must be removed.
        self::assertStringNotContainsString("\r", $stored, 'Carriage-return must be stripped');
        self::assertStringNotContainsString("\n", $stored, 'Newline must be stripped');
        // After stripping CRLFs the remainder is a single safe header value —
        // the text "X-Injected" as plain text is harmless without the preceding CRLF.
        self::assertStringNotContainsString("\r\n", $stored);
    }

    public function testOversizedCorrelationIdIsTruncatedTo128Characters(): void
    {
        $oversized = str_repeat('a', 300);
        $request   = Request::create('/test');
        $request->headers->set('X-Correlation-ID', $oversized);

        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onRequest($event);

        $stored = $request->attributes->get('correlation_id');

        self::assertLessThanOrEqual(128, strlen((string) $stored));
    }

    public function testNonAsciiControlCharactersAreStripped(): void
    {
        $withNull  = "valid-id\x00extra";
        $request   = Request::create('/test');
        $request->headers->set('X-Correlation-ID', $withNull);

        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onRequest($event);

        $stored = $request->attributes->get('correlation_id');
        self::assertStringNotContainsString("\x00", (string) $stored);
    }
}
