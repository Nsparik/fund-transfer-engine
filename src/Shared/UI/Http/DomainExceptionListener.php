<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use App\Shared\Domain\Exception\DomainExceptionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts domain exceptions into JSON HTTP responses.
 *
 * Runs before Symfony's default exception handler (priority 10).
 * Only intercepts exceptions that implement DomainExceptionInterface —
 * all other exceptions fall through to Symfony's default error handling.
 *
 * ## Adding a new module
 *   1. Make the module's base exception implement DomainExceptionInterface.
 *   2. Add any non-422 domain codes to HTTP_STATUS_MAP below.
 *   3. No other changes needed here (Open/Closed principle).
 *
 * ## HTTP status mapping
 *
 * Status codes are resolved from HTTP_STATUS_MAP by the exception's own
 * domain code, so this Shared listener does not import any concrete
 * exception type from any module.  New exceptions default to 422 unless
 * an explicit entry is added to the map.
 *
 * ## Response body
 *
 *   { "error": { "code": "TRANSFER_NOT_FOUND", "message": "..." } }
 */
final class DomainExceptionListener implements EventSubscriberInterface
{
    /**
     * Domain codes that map to a non-422 HTTP status.
     * Everything else defaults to 422 Unprocessable Content.
     *
     * @var array<string, int>
     */
    private const HTTP_STATUS_MAP = [
        // Transfer module
        'TRANSFER_NOT_FOUND'     => JsonResponse::HTTP_NOT_FOUND,
        'INVALID_TRANSFER_STATE' => JsonResponse::HTTP_CONFLICT,
        // Account module
        'ACCOUNT_NOT_FOUND'           => JsonResponse::HTTP_NOT_FOUND,
        'ACCOUNT_FROZEN'              => JsonResponse::HTTP_CONFLICT,  // temporary state — can be unfrozen
        'ACCOUNT_CLOSED'              => JsonResponse::HTTP_CONFLICT,  // terminal state — permanent
        'INVALID_ACCOUNT_STATE'       => JsonResponse::HTTP_CONFLICT,
        'NON_ZERO_BALANCE_ON_CLOSE'   => JsonResponse::HTTP_CONFLICT,  // current balance blocks close — drain first
        // Cross-module: optimistic lock conflict
        'OPTIMISTIC_LOCK_CONFLICT' => JsonResponse::HTTP_CONFLICT,
        // Cross-module: transfer validation
        'CURRENCY_MISMATCH'      => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
        // Ledger module
        'INVALID_DATE_RANGE'     => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
        // INSUFFICIENT_FUNDS, SAME_ACCOUNT_TRANSFER, INVALID_TRANSFER_AMOUNT → 422 (default)
        // Account module: balance arithmetic overflow → 422
        'BALANCE_OVERFLOW'       => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
    ];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        if (!$e instanceof DomainExceptionInterface) {
            return;
        }

        $domainCode = $e->getDomainCode();
        $httpStatus = self::HTTP_STATUS_MAP[$domainCode] ?? JsonResponse::HTTP_UNPROCESSABLE_ENTITY;

        $event->setResponse(new JsonResponse([
            'error' => [
                'code'    => $domainCode,
                'message' => $e->getMessage(),
            ],
        ], $httpStatus));
    }
}
