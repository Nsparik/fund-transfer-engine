<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces Content-Type: application/json on all request-with-body methods
 * (POST, PUT, PATCH).
 *
 * Returns HTTP 415 Unsupported Media Type when the Content-Type header is
 * absent or does not start with "application/json".  A structured JSON body
 * is returned so clients receive a machine-readable error code.
 *
 * ## Why enforce Content-Type?
 *   Without this guard, a client sending "application/x-www-form-urlencoded"
 *   data reaches the controller, which calls json_decode() on the raw string
 *   and returns a confusing 400 INVALID_JSON.  Enforcing the content type
 *   here gives a clear 415 before any business logic executes.
 *
 * ## Priority
 *   Runs at priority 30 on kernel.request — before IdempotencySubscriber (20),
 *   before routing (-200), and before any controller invocation.  Requests
 *   with the wrong content type are short-circuited before any DB work.
 *
 * ## Scope
 *   POST, PUT, PATCH only.  GET, DELETE, HEAD, OPTIONS pass through untouched
 *   (those methods carry no body by convention and need no Content-Type).
 *
 * ## Accepted values
 *   Any value starting with "application/json" (case-insensitive) is accepted,
 *   e.g. "application/json", "application/json; charset=utf-8".
 */
final class ContentTypeSubscriber implements EventSubscriberInterface
{
    /** HTTP methods that carry a request body and must declare Content-Type. */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 30]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!in_array($request->getMethod(), self::BODY_METHODS, true)) {
            return;
        }

        $contentType = $request->headers->get('Content-Type', '');

        // Accept "application/json" with optional parameters, e.g.:
        //   "application/json"
        //   "application/json; charset=utf-8"
        //   "application/json;charset=UTF-8"
        if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
            $event->setResponse(new JsonResponse([
                'error' => [
                    'code'    => 'UNSUPPORTED_MEDIA_TYPE',
                    'message' => 'Content-Type must be application/json.',
                ],
            ], JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE));
        }
    }
}
