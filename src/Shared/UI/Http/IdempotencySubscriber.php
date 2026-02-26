<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use App\Shared\Domain\Exception\IdempotencyKeyReuseException;
use App\Shared\Domain\Idempotency\IdempotencyRecord;
use App\Shared\Domain\Idempotency\IdempotencyRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces idempotency on POST /transfers and POST /accounts via the
 * X-Idempotency-Key header.
 *
 * ## Why idempotency matters for money movement
 *   Network timeouts and client retries are inevitable at scale. Without this
 *   layer, a retry after a timeout could debit the source account twice.
 *   With this layer, the second POST returns the exact same response as the
 *   first — no handler is invoked a second time.
 *
 * ## Request flow
 *
 *   Client sends POST /transfers with X-Idempotency-Key: <uuid>
 *
 *   First request:
 *     onRequest  → key not in DB → acquire advisory lock → re-check DB →
 *                  still absent → store key+hash in request attributes → continue
 *     Handler runs, produces response
 *     onResponse → save IdempotencyRecord(key, hash, status, body) → release lock
 *
 *   Retry (same key, same body):
 *     onRequest  → key found in DB on initial check → short-circuit with cached
 *                  response; handler NEVER called again
 *
 *   Concurrent duplicate first-request (race condition):
 *     Request A: acquires advisory lock, proceeds to handler
 *     Request B: calls acquireLock() → blocks (MySQL GET_LOCK, up to 5 s)
 *     Request A: handler completes, onResponse saves record, releases lock
 *     Request B: lock acquired → re-checks DB → record now found → returns
 *                cached response; handler NOT called a second time
 *
 *   Retry (same key, different body):
 *     onRequest  → key found in DB, hash MISMATCH → 422 IDEMPOTENCY_KEY_REUSE
 *
 * ## Missing key behaviour
 *   POST /transfers and POST /accounts require the header.  Requests without
 *   X-Idempotency-Key are rejected with 400 IDEMPOTENCY_KEY_REQUIRED.
 *   Other covered routes (e.g. POST /transfers/{id}/reverse) treat the key
 *   as optional; those requests are processed normally without idempotency
 *   protection when no key is supplied.
 *
 * ## Lock timeout behaviour
 *   If GET_LOCK() times out (> 5 s wait), the subscriber logs a warning and
 *   proceeds without the lock. The final INSERT IGNORE in save() still prevents
 *   double-persistence, but a concurrent handler execution remains possible
 *   under extreme DB latency. This is an acceptable last-resort safety net.
 *
 * ## Scope
 *   Applies to all POST requests whose path starts with /transfers or /accounts.
 */
final class IdempotencySubscriber implements EventSubscriberInterface
{
    public const  IDEMPOTENCY_KEY_ATTR  = '_idempotency_key';
    private const IDEMPOTENCY_HASH_ATTR = '_idempotency_hash';
    private const CACHE_HIT_ATTR        = '_idempotency_cache_hit';
    /** Maximum length enforced by the idempotency_keys table (VARCHAR 255). */
    private const MAX_KEY_LENGTH           = 255;
    /**
     * Request attribute set when this request holds the advisory lock.
     * Used by onResponse/onException to know whether to call releaseLock().
     */
    private const LOCK_ACQUIRED_ATTR       = '_idempotency_lock_acquired';

    public function __construct(
        private readonly IdempotencyRepositoryInterface $idempotencyRepository,
        private readonly LoggerInterface                $logger,
        /**
         * How long an idempotency record is kept before expiry.
         * Defaults to 24 hours.  Increase for settlement or batch-retry systems
         * that may legitimately retry the same key on a next-day schedule.
         * Configured via services.yaml → $idempotencyTtlHours.
         */
        private readonly int                            $idempotencyTtlHours = 24,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest',   20],   // after firewall (priority 8), before routing (priority -200)
            KernelEvents::RESPONSE  => ['onResponse', -20],   // after main response listeners
            KernelEvents::EXCEPTION => ['onException', -20],  // cache error responses too (e.g. 422 domain failures)
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->appliesToRequest($request)) {
            return;
        }

        $key = $request->headers->get('X-Idempotency-Key');

        if ($key === null || $key === '') {
            if ($this->requiresIdempotencyKey($request)) {
                // Reject the request: callers of the money-movement and
                // account-creation endpoints must supply a stable client-generated
                // key so that retries after a network timeout are safe and do not
                // produce duplicate money movements or duplicate accounts.
                $event->setResponse(new JsonResponse([
                    'error' => [
                        'code'    => 'IDEMPOTENCY_KEY_REQUIRED',
                        'message' => 'The X-Idempotency-Key header is required for this request. '
                                   . 'Supply a client-generated UUID so retries are safe and '
                                   . 'duplicate money movements are prevented.',
                    ],
                ], JsonResponse::HTTP_BAD_REQUEST));
            }

            // For other covered routes (e.g. POST /transfers/{id}/reverse) the
            // key is optional.  Skip idempotency protection for this request.
            return;
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            $event->setResponse(new JsonResponse([
                'error' => [
                    'code'    => 'INVALID_IDEMPOTENCY_KEY',
                    'message' => sprintf(
                        'X-Idempotency-Key must not exceed %d characters.',
                        self::MAX_KEY_LENGTH,
                    ),
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY));

            return;
        }

        $hash = $this->hashRequest($request);

        $existing = $this->idempotencyRepository->findByKey($key);

        if ($existing !== null) {
            if (!$existing->matchesRequestHash($hash)) {
                $exception = new IdempotencyKeyReuseException(
                    sprintf(
                        'Idempotency key "%s" was already used with a different request body. '
                        . 'Each idempotency key must map to exactly one set of request parameters.',
                        $key,
                    )
                );

                $event->setResponse(new JsonResponse([
                    'error' => [
                        'code'    => $exception->getDomainCode(),
                        'message' => $exception->getMessage(),
                    ],
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY));

                return;
            }

            $request->attributes->set(self::CACHE_HIT_ATTR, true);

            $this->logger->info('idempotency.cache_hit', [
                'idempotency_key' => $key,
                'request_path'    => $request->getPathInfo(),
                'cached_status'   => $existing->responseStatus,
            ]);

            $event->setResponse(new JsonResponse(
                $existing->responseBody,
                $existing->responseStatus,
            ));

            return;
        }

        // First request (so far) — acquire an advisory lock to serialise
        // any concurrent requests carrying the same key before we allow
        // the handler to execute.
        //
        // The lock prevents the race where two simultaneous requests both
        // pass the findByKey() check above, both execute the handler, and
        // both commit a money movement.  The second request blocks here until
        // the first has finished and called releaseLock() in onResponse /
        // onException.  It then re-checks findByKey() and hits the cache.
        $lockAcquired = $this->idempotencyRepository->acquireLock($key);
        // Advisory lock is connection-scoped.
        // Safe under standard PHP-FPM (one MySQL connection per request).
        // If a PgBouncer-style MySQL proxy or PHP persistent connections
        // (pconnect) are ever introduced, two workers could share a connection
        // and both calls to GET_LOCK would succeed re-entrantly — breaking the
        // mutual-exclusion guarantee.  Review this assumption before enabling
        // connection pooling or persistent connections in production.
        if (!$lockAcquired) {
            // GET_LOCK() timed out (> 5 s) — another request is still
            // executing under this idempotency key.  Proceeding without the lock would
            // allow two concurrent handlers to run simultaneously, violating the
            // exactly-once execution guarantee.  Return 503 so the client retries
            // after the in-flight request has had time to complete and release the lock.
            $this->logger->warning('idempotency.lock_timeout', [
                'idempotency_key' => $key,
                'request_path'    => $request->getPathInfo(),
            ]);

            $event->setResponse(new JsonResponse(
                [
                    'error' => [
                        'code'    => 'IDEMPOTENCY_LOCK_TIMEOUT',
                        'message' => 'A concurrent request with the same idempotency key is still '
                            . 'being processed. Retry after a few seconds.',
                    ],
                ],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '5'],
            ));

            return;
        }

        // Re-check inside the lock: a concurrent request may have committed
        // the record while we were waiting for the lock.
        $existing = $this->idempotencyRepository->findByKey($key);

        if ($existing !== null) {
            $this->idempotencyRepository->releaseLock($key);

            if (!$existing->matchesRequestHash($hash)) {
                $exception = new IdempotencyKeyReuseException(
                    sprintf(
                        'Idempotency key "%s" was already used with a different request body. '
                        . 'Each idempotency key must map to exactly one set of request parameters.',
                        $key,
                    )
                );

                $event->setResponse(new JsonResponse([
                    'error' => [
                        'code'    => $exception->getDomainCode(),
                        'message' => $exception->getMessage(),
                    ],
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY));

                return;
            }

            $request->attributes->set(self::CACHE_HIT_ATTR, true);

            $this->logger->info('idempotency.cache_hit_after_lock', [
                'idempotency_key' => $key,
                'request_path'    => $request->getPathInfo(),
                'cached_status'   => $existing->responseStatus,
            ]);

            $event->setResponse(new JsonResponse(
                $existing->responseBody,
                $existing->responseStatus,
            ));

            return;
        }

        $request->attributes->set(self::IDEMPOTENCY_KEY_ATTR,    $key);
        $request->attributes->set(self::IDEMPOTENCY_HASH_ATTR,   $hash);
        $request->attributes->set(self::LOCK_ACQUIRED_ATTR, $lockAcquired);

        $this->logger->debug('idempotency.first_request', [
            'idempotency_key' => $key,
            'request_path'    => $request->getPathInfo(),
        ]);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->appliesToRequest($request)) {
            return;
        }

        if ($request->attributes->get(self::CACHE_HIT_ATTR)) {
            return;
        }

        $key  = $request->attributes->get(self::IDEMPOTENCY_KEY_ATTR);
        $hash = $request->attributes->get(self::IDEMPOTENCY_HASH_ATTR);

        if ($key === null) {
            return;
        }

        $response = $event->getResponse();

        // Release the advisory lock BEFORE the json_decode attempt.
        // If json_decode throws \JsonException (non-JSON response body), the
        // old code returned without releasing, holding the lock until MySQL
        // connection teardown.  The next retry would block for the full
        // GET_LOCK timeout (5 s) before being able to acquire it.
        if ($request->attributes->get(self::LOCK_ACQUIRED_ATTR)) {
            $this->idempotencyRepository->releaseLock($key);
        }

        try {
            $body = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        $this->idempotencyRepository->save(IdempotencyRecord::create(
            idempotencyKey: $key,
            requestHash:    $hash,
            responseStatus: $response->getStatusCode(),
            responseBody:   is_array($body) ? $body : [],
            ttlHours:       $this->idempotencyTtlHours,
        ));
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->appliesToRequest($request)) {
            return;
        }

        if ($request->attributes->get(self::CACHE_HIT_ATTR)) {
            return;
        }

        $key  = $request->attributes->get(self::IDEMPOTENCY_KEY_ATTR);
        $hash = $request->attributes->get(self::IDEMPOTENCY_HASH_ATTR);

        if ($key === null) {
            return;
        }

        // Always release the advisory lock on exception paths,
        // even when no response has been set yet.  For uncaught non-domain
        // exceptions, DomainExceptionListener (priority 10) does NOT set a
        // response.  Our subscriber runs at priority -20, BEFORE Symfony's
        // own exception renderer (priority -128) sets an HTML response.
        // Without this unconditional release, the lock is held until MySQL
        // connection teardown, forcing the next retry to block for the full
        // GET_LOCK timeout (5 s).
        if ($request->attributes->get(self::LOCK_ACQUIRED_ATTR)) {
            $this->idempotencyRepository->releaseLock($key);
        }

        if (!$event->hasResponse()) {
            return;
        }

        $response = $event->getResponse();

        try {
            $body = json_decode($response->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        $this->idempotencyRepository->save(IdempotencyRecord::create(
            idempotencyKey: $key,
            requestHash:    $hash,
            responseStatus: $response->getStatusCode(),
            responseBody:   is_array($body) ? $body : [],
            ttlHours:       $this->idempotencyTtlHours,
        ));
    }

    private function appliesToRequest(Request $request): bool
    {
        if (!$request->isMethod('POST')) {
            return false;
        }

        $path = $request->getPathInfo();

        return str_starts_with($path, '/transfers')
            || str_starts_with($path, '/accounts');
    }

    /**
     * Returns true for the two endpoints where an idempotency key is mandatory.
     *
     * POST /transfers and POST /accounts create financial resources; a missing
     * key on these routes means a retry after a network timeout could produce a
     * duplicate debit or a duplicate account.  The exact-path check intentionally
     * excludes sub-resource operations (e.g. POST /transfers/{id}/reverse,
     * POST /accounts/{id}/freeze) because the domain layer already makes those
     * operations idempotent at the state-machine level.
     */
    private function requiresIdempotencyKey(Request $request): bool
    {
        $path = rtrim($request->getPathInfo(), '/');

        return $path === '/transfers' || $path === '/accounts';
    }

    /**
     * Produce a SHA-256 fingerprint of the request that is unique per
     * (HTTP method, path, body) triplet.
     *
     * Including method and path prevents an empty-body POST /transfers/{id}/reverse
     * from colliding with an empty-body POST /transfers/{id2}/reverse when
     * a client accidentally reuses the same idempotency key for two different
     * operations.
     */
    private function hashRequest(Request $request): string
    {
        return hash('sha256', $request->getMethod() . '|' . $request->getPathInfo() . '|' . $request->getContent());
    }
}
