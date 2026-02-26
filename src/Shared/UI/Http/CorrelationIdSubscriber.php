<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdSubscriber implements EventSubscriberInterface
{
    private const HEADER = 'X-Correlation-ID';

    /**
     * Maximum character length for a client-supplied correlation ID.
     * Prevents oversized values from polluting log lines.
     */
    private const MAX_LENGTH = 128;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onRequest', 100],
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $incoming = $request->headers->get(self::HEADER);

        if ($incoming !== null) {
            $correlationId = $this->sanitize($incoming);
        } else {
            $correlationId = (string) Uuid::v4();
        }

        $request->attributes->set('correlation_id', $correlationId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $correlationId = $event->getRequest()->attributes->get('correlation_id');

        if ($correlationId !== null) {
            $event->getResponse()->headers->set(self::HEADER, $correlationId);
        }
    }

    /**
     * Strip CRLF characters and truncate to prevent HTTP response header splitting
     * and log injection attacks when an untrusted client supplies the header value.
     *
     * Only printable ASCII (0x20–0x7E) is kept so the value is safe to embed
     * in both HTTP headers and structured log records.
     */
    private function sanitize(string $value): string
    {
        // Remove all control characters (includes \r, \n, \t, null, etc.)
        $safe = preg_replace('/[^\x20-\x7E]/', '', $value);

        // Truncate to max length
        return mb_substr((string) $safe, 0, self::MAX_LENGTH);
    }
}
