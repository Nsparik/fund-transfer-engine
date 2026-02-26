<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\UI\Http;

use App\Shared\Domain\Idempotency\IdempotencyRecord;
use App\Shared\Domain\Idempotency\IdempotencyRepositoryInterface;
use App\Shared\UI\Http\IdempotencySubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for IdempotencySubscriber.
 *
 * All interaction with MySQL is replaced by a mock repository.
 * The subscriber's behaviour is tested purely through Kernel events.
 */
final class IdempotencySubscriberTest extends TestCase
{
    /** @var IdempotencyRepositoryInterface&MockObject */
    private IdempotencyRepositoryInterface $repository;

    private IdempotencySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $this->subscriber  = new IdempotencySubscriber($this->repository, new NullLogger());
    }

    // ── getSubscribedEvents ───────────────────────────────────────────────────

    public function testSubscribedEventsCoversRequestResponseAndException(): void
    {
        $events = IdempotencySubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST,   $events);
        self::assertArrayHasKey(KernelEvents::RESPONSE,  $events);
        self::assertArrayHasKey(KernelEvents::EXCEPTION, $events);
    }

    // ── onRequest — happy path ────────────────────────────────────────────────

    public function testFirstRequestWithKeyPassesThroughAndStoresAttributesOnRequest(): void
    {
        // findByKey is called twice: once before acquiring the lock, once
        // inside the lock (to detect a concurrent request that committed
        // between the two checks).  Both return null on a genuine first request.
        $this->repository->expects(self::exactly(2))
            ->method('findByKey')
            ->willReturn(null);

        $this->repository->expects(self::once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->repository->expects(self::never())->method('save');

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        $request->headers->set('X-Idempotency-Key', 'key-001');

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        self::assertFalse($event->hasResponse(), 'Handler must not be bypassed on first request');
        self::assertSame('key-001', $request->attributes->get('_idempotency_key'));
        self::assertNotNull($request->attributes->get('_idempotency_hash'));
    }

    public function testMissingKeyOnRequiredRouteReturns400(): void
    {
        // POST /transfers (exact path) requires X-Idempotency-Key.
        // Requests without the header must be rejected with 400 so that
        // clients cannot trigger duplicate money movements via retries.
        $this->repository->expects(self::never())->method('findByKey');
        $this->repository->expects(self::never())->method('acquireLock');

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        // Deliberately: no X-Idempotency-Key header

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        self::assertTrue($event->hasResponse(), 'Missing key on /transfers must short-circuit with 400');
        self::assertSame(400, $event->getResponse()->getStatusCode());

        $body = json_decode($event->getResponse()->getContent(), true);
        self::assertSame('IDEMPOTENCY_KEY_REQUIRED', $body['error']['code']);
    }

    public function testMissingKeyOnRequiredAccountsRouteReturns400(): void
    {
        // POST /accounts (exact path) also requires X-Idempotency-Key.
        $this->repository->expects(self::never())->method('findByKey');

        $request = Request::create('/accounts', 'POST', [], [], [], [], '{}');
        // No X-Idempotency-Key header

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        self::assertTrue($event->hasResponse(), 'Missing key on /accounts must short-circuit with 400');
        self::assertSame(400, $event->getResponse()->getStatusCode());

        $body = json_decode($event->getResponse()->getContent(), true);
        self::assertSame('IDEMPOTENCY_KEY_REQUIRED', $body['error']['code']);
    }

    // ── onRequest — server-generated idempotency key ──────────────────────────

    /**
     * Sub-path routes like POST /transfers/{id}/reverse are covered by the
     * subscriber (they start with /transfers) but do NOT require the key.
     * A missing header on these routes must pass through without any response
     * being set — idempotency is simply skipped.
     */
    public function testMissingKeyOnOptionalSubPathRoutePassesThrough(): void
    {
        // POST /transfers/{id}/reverse — key is optional; subscriber must not
        // set a response when the key is absent.
        $this->repository->expects(self::never())->method('findByKey');
        $this->repository->expects(self::never())->method('acquireLock');

        $request = Request::create('/transfers/abc-123/reverse', 'POST', [], [], [], [], '{}');
        // Deliberately: no X-Idempotency-Key header

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        self::assertFalse($event->hasResponse(), 'Optional-key route without header must not be short-circuited');
    }

    /**
     * When the key was provided by the client, onResponse must NOT
     * add X-Idempotency-Key to the response header.
     */
    public function testClientProvidedKeyIsNotReflectedInResponseHeader(): void
    {
        $this->repository->method('save');

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        $request->attributes->set('_idempotency_key',  'client-provided-key');
        $request->attributes->set('_idempotency_hash', hash('sha256', 'POST|/transfers|{"amount":1000}'));
        // The subscriber never echoes X-Idempotency-Key in the response —
        // the client that supplied the key already knows it.

        $response = new JsonResponse(['data' => ['id' => 'tx-client']], 201);
        $event    = $this->makeResponseEvent($request, $response);

        $this->subscriber->onResponse($event);

        self::assertNull(
            $event->getResponse()->headers->get('X-Idempotency-Key'),
            'X-Idempotency-Key must NOT be echoed in the response header',
        );
    }

    public function testNonPostRequestIsIgnored(): void
    {
        $this->repository->expects(self::never())->method('findByKey');

        $request = Request::create('/transfers', 'GET');
        $request->headers->set('X-Idempotency-Key', 'key-001');

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testPostToUncoveredPathIsIgnored(): void
    {
        // The subscriber only covers /transfers* and /accounts*.
        // Any other path (e.g. /payments) must pass through untouched.
        $this->repository->expects(self::never())->method('findByKey');

        $request = Request::create('/payments', 'POST', [], [], [], [], '{}');
        $request->headers->set('X-Idempotency-Key', 'key-001');

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        self::assertFalse($event->hasResponse());
    }

    // ── onRequest — cache hit ─────────────────────────────────────────────────

    public function testCacheHitShortCircuitsRequestWithStoredResponse(): void
    {
        $body = ['data' => ['id' => 'transfer-uuid-123']];
        // Hash must match IdempotencySubscriber::hashRequest(): method|path|body
        $hash = hash('sha256', 'POST|/transfers|{"amount":1000}');

        $record = $this->makeRecord('key-001', $hash, 200, $body);

        $this->repository->expects(self::once())
            ->method('findByKey')
            ->with('key-001')
            ->willReturn($record);

        $this->repository->expects(self::never())->method('save');

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        $request->headers->set('X-Idempotency-Key', 'key-001');

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        self::assertTrue($event->hasResponse(), 'Cache hit must set a response to bypass the handler');
        self::assertSame(200, $event->getResponse()->getStatusCode());
        self::assertTrue($request->attributes->get('_idempotency_cache_hit'));
    }

    public function testCacheHitResponseBodyMatchesStoredBody(): void
    {
        $body = ['data' => ['transfer_id' => 'abc', 'status' => 'COMPLETED']];
        // Hash must match IdempotencySubscriber::hashRequest(): method|path|body
        $hash = hash('sha256', 'POST|/transfers|{"amount":500}');

        $record = $this->makeRecord('key-002', $hash, 201, $body);

        $this->repository->method('findByKey')->willReturn($record);

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":500}');
        $request->headers->set('X-Idempotency-Key', 'key-002');

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        $responseBody = json_decode($event->getResponse()->getContent(), true);
        self::assertSame($body, $responseBody);
    }

    // ── onRequest — key reuse (hash mismatch) ─────────────────────────────────

    public function testKeyReuseWithDifferentBodyReturns422(): void
    {
        $storedHash = hash('sha256', '{"amount":1000}');
        $record     = $this->makeRecord('key-001', $storedHash, 200, ['data' => []]);

        $this->repository->method('findByKey')->willReturn($record);

        // Different body sent with the same key
        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":9999}');
        $request->headers->set('X-Idempotency-Key', 'key-001');

        $event = $this->makeRequestEvent($request);
        $this->subscriber->onRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $event->getResponse()->getStatusCode());

        $responseBody = json_decode($event->getResponse()->getContent(), true);
        self::assertSame('IDEMPOTENCY_KEY_REUSE', $responseBody['error']['code']);
    }

    // ── onResponse — save after successful handler ────────────────────────────

    public function testOnResponseSavesRecordAfterHandlerRunsSuccessfully(): void
    {
        $savedRecord = null;
        $this->repository->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (IdempotencyRecord $r) use (&$savedRecord): void {
                $savedRecord = $r;
            });

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        $request->headers->set('X-Idempotency-Key', 'key-001');
        $request->attributes->set('_idempotency_key',  'key-001');
        $request->attributes->set('_idempotency_hash', hash('sha256', '{"amount":1000}'));

        $response = new JsonResponse(['data' => ['id' => 'tx-1']], 201);
        $event    = $this->makeResponseEvent($request, $response);

        $this->subscriber->onResponse($event);

        self::assertNotNull($savedRecord);
        self::assertSame('key-001', $savedRecord->idempotencyKey);
        self::assertSame(201, $savedRecord->responseStatus);
    }

    public function testOnResponseDoesNotSaveWhenNoKeyAttributePresent(): void
    {
        $this->repository->expects(self::never())->method('save');

        $request  = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        // No attributes set — simulates a request that had no X-Idempotency-Key
        $response = new JsonResponse(['data' => []], 201);
        $event    = $this->makeResponseEvent($request, $response);

        $this->subscriber->onResponse($event);
    }

    public function testOnResponseDoesNotSaveOnCacheHit(): void
    {
        $this->repository->expects(self::never())->method('save');

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        $request->attributes->set('_idempotency_key',        'key-001');
        $request->attributes->set('_idempotency_hash',       hash('sha256', '{"amount":1000}'));
        $request->attributes->set('_idempotency_cache_hit',  true);  // ← already a cached response

        $response = new JsonResponse(['data' => []], 200);
        $event    = $this->makeResponseEvent($request, $response);

        $this->subscriber->onResponse($event);
    }

    public function testOnResponseIgnoresRoutesNotCoveredBySubscriber(): void
    {
        // POST /payments is not a covered route (subscriber only covers
        // /transfers* and /accounts*); onResponse must return immediately
        // without touching the repository.
        $this->repository->expects(self::never())->method('save');

        $request  = Request::create('/payments', 'POST', [], [], [], [], '{}');
        $response = new JsonResponse(['data' => []], 201);
        $event    = $this->makeResponseEvent($request, $response);

        $this->subscriber->onResponse($event);
    }

    // ── onException — cache domain-error responses ────────────────────────────

    public function testOnExceptionSavesDomainErrorResponseAfterItIsSet(): void
    {
        $savedRecord = null;
        $this->repository->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (IdempotencyRecord $r) use (&$savedRecord): void {
                $savedRecord = $r;
            });

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        $request->attributes->set('_idempotency_key',  'key-001');
        $request->attributes->set('_idempotency_hash', hash('sha256', '{"amount":1000}'));

        $domainErrorResponse = new JsonResponse(
            ['error' => ['code' => 'INSUFFICIENT_FUNDS', 'message' => 'Not enough funds']],
            422,
        );

        $event = $this->makeExceptionEvent($request, new \RuntimeException('domain'), $domainErrorResponse);
        $this->subscriber->onException($event);

        self::assertNotNull($savedRecord);
        self::assertSame(422, $savedRecord->responseStatus);
    }

    public function testOnExceptionDoesNothingWhenNoResponseIsSet(): void
    {
        $this->repository->expects(self::never())->method('save');

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        $request->attributes->set('_idempotency_key',  'key-001');
        $request->attributes->set('_idempotency_hash', hash('sha256', '{"amount":1000}'));

        // ExceptionEvent without a response set (no DomainExceptionListener ran)
        $event = $this->makeExceptionEvent($request, new \RuntimeException('unhandled'));
        $this->subscriber->onException($event);
    }

    public function testOnExceptionDoesNotSaveOnCacheHit(): void
    {
        $this->repository->expects(self::never())->method('save');

        $request = Request::create('/transfers', 'POST', [], [], [], [], '{"amount":1000}');
        $request->attributes->set('_idempotency_cache_hit', true);
        $request->attributes->set('_idempotency_key',       'key-001');

        $event = $this->makeExceptionEvent($request, new \RuntimeException(), new JsonResponse([], 200));
        $this->subscriber->onException($event);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function makeRequestEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeResponseEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    private function makeExceptionEvent(
        Request    $request,
        \Throwable $exception,
        ?Response  $response = null,
    ): ExceptionEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event  = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        if ($response !== null) {
            $event->setResponse($response);
        }

        return $event;
    }

    private function makeRecord(
        string $key,
        string $hash,
        int    $status,
        array  $body,
    ): IdempotencyRecord {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new IdempotencyRecord(
            idempotencyKey: $key,
            requestHash:    $hash,
            responseStatus: $status,
            responseBody:   $body,
            createdAt:      $now,
            expiresAt:      $now->modify('+24 hours'),
        );
    }
}
