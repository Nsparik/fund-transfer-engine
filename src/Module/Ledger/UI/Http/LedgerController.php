<?php

declare(strict_types=1);

namespace App\Module\Ledger\UI\Http;

use App\Module\Ledger\Application\Query\FindAccountStatement\FindAccountStatementHandler;
use App\Module\Ledger\Application\Query\FindAccountStatement\FindAccountStatementQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller for the Ledger resource.
 *
 * ## Endpoints
 *   GET /accounts/{id}/statement  — account statement for a given UTC date range
 *
 * ## Response envelope
 *   Success (200):
 *     { "data": { ...AccountStatementDTO fields... } }
 *
 *   Error (400, 404, 422):
 *     { "error": { "code": "...", "message": "..." } }
 *     — produced by DomainExceptionListener (404 on AccountNotFoundException,
 *       422 on InvalidDateRangeException, 400 on validation failures here)
 *
 * ## Route placement
 *   The route lives under /accounts/{id}/ because the statement is a sub-resource
 *   of an Account, not a standalone Ledger resource.  The Ledger module owns the
 *   handler and route; AccountController is not touched.
 */
#[Route('/accounts', name: 'ledger')]
final class LedgerController extends AbstractController
{
    public function __construct(
        private readonly FindAccountStatementHandler $statementHandler,
    ) {}

    /**
     * GET /accounts/{id}/statement
     *
     * Query parameters:
     *   from     — ISO 8601 UTC start of range (required), e.g. "2026-01-01T00:00:00Z"
     *   to       — ISO 8601 UTC end   of range (required), e.g. "2026-01-31T23:59:59Z"
     *   page     — 1-based page number (default 1)
     *   per_page — items per page, 1–100 (default 50)
     *
     * Errors:
     *   400 — missing or empty from/to; invalid page/per_page
     *   404 — account not found (AccountNotFoundException → DomainExceptionListener)
     *   422 — invalid date range (from >= to, range > 366 days)
     */
    #[Route(
        '/{id}/statement',
        name: '_statement',
        methods: ['GET'],
        requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
    )]
    public function statement(string $id, Request $request): JsonResponse
    {
        $from    = trim((string) $request->query->get('from', ''));
        $to      = trim((string) $request->query->get('to', ''));
        $page    = (int) $request->query->get('page', 1);
        $perPage = (int) $request->query->get('per_page', 50);

        // ── Validate required parameters ─────────────────────────────────────
        if ($from === '') {
            return new JsonResponse([
                'error' => [
                    'code'    => 'MISSING_PARAMETER',
                    'message' => 'Query parameter "from" is required (ISO 8601 UTC datetime).',
                ],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($to === '') {
            return new JsonResponse([
                'error' => [
                    'code'    => 'MISSING_PARAMETER',
                    'message' => 'Query parameter "to" is required (ISO 8601 UTC datetime).',
                ],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($page < 1) {
            return new JsonResponse([
                'error' => ['code' => 'INVALID_PAGE', 'message' => 'Page must be at least 1.'],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($perPage < 1 || $perPage > 100) {
            return new JsonResponse([
                'error' => ['code' => 'INVALID_PER_PAGE', 'message' => 'per_page must be between 1 and 100.'],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Domain exceptions (InvalidDateRangeException, AccountNotFoundException)
        // bubble to DomainExceptionListener — no try/catch needed here.
        $dto = ($this->statementHandler)(new FindAccountStatementQuery(
            accountId: $id,
            from:      $from,
            to:        $to,
            page:      $page,
            perPage:   $perPage,
        ));

        return new JsonResponse(['data' => $dto->toArray()]);
    }
}
