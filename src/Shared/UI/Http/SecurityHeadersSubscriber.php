<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Appends security-related HTTP response headers to every response.
 *
 * Runs at priority -200 so it fires after all controllers and exception
 * listeners have already set the response body and status.
 *
 * Headers set:
 *   X-Content-Type-Options    — prevent MIME-sniffing
 *   X-Frame-Options           — block framing (clickjacking)
 *   X-XSS-Protection          — legacy browser XSS filter (belt-and-suspenders)
 *   Referrer-Policy           — control referrer leakage
 *   Content-Security-Policy   — default-src 'none' (API only — no browser assets)
 *   Cache-Control / Pragma    — never cache API responses at a shared proxy
 *   Strict-Transport-Security — enforce HTTPS; max-age=1 year + includeSubDomains
 *   Permissions-Policy        — disable browser features irrelevant to an API
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -200]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        // HSTS: enforce HTTPS for 1 year, including all subdomains.
        // Only effective over HTTPS — browsers ignore this header on HTTP.
        // The preload directive readies the domain for browser HSTS preload lists.
        $response->headers->set(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains; preload',
        );

        // Permissions-Policy: disable browser features that have no place in a
        // pure JSON API — prevents feature abuse if a response is ever rendered
        // in a browser context.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        );
    }
}
