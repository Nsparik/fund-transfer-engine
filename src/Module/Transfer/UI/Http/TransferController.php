<?php

declare(strict_types=1);

namespace App\Module\Transfer\UI\Http;

use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferCommand;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferHandler;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferCommand;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferHandler;
use App\Module\Transfer\Application\Query\FindTransferById\FindTransferByIdHandler;
use App\Module\Transfer\Application\Query\FindTransferById\FindTransferByIdQuery;
use App\Module\Transfer\Application\Query\FindTransfersByFilters\FindTransfersByFiltersHandler;
use App\Module\Transfer\Application\Query\FindTransfersByFilters\FindTransfersByFiltersQuery;
use App\Module\Transfer\Domain\Model\TransferStatus;
use App\Shared\UI\Http\IdempotencySubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * REST controller for the Transfer resource.
 *
 * ## Response envelope
 *
 *   Success:
 *     { "data": { ...TransferDTO fields... } }
 *
 *   Validation error (400):
 *     { "error": { "code": "VALIDATION_ERROR", "message": "...", "violations": [...] } }
 *
 *   Not found (404) / Domain error (409, 422):
 *     { "error": { "code": "TRANSFER_NOT_FOUND", "message": "..." } }
 *     — produced by DomainExceptionListener, not this class
 */
#[Route('/transfers', name: 'transfers')]
final class TransferController extends AbstractController
{
    public function __construct(
        private readonly InitiateTransferHandler         $initiateHandler,
        private readonly FindTransferByIdHandler         $findHandler,
        private readonly FindTransfersByFiltersHandler   $findByFiltersHandler,
        private readonly ReverseTransferHandler          $reverseHandler,
        private readonly RateLimiterFactory              $transferCreationLimiter,
        private readonly ValidatorInterface              $validator,
        private readonly LoggerInterface                 $logger,
    ) {}

    #[Route('', name: '_initiate', methods: ['POST'])]
    public function initiate(Request $request): JsonResponse
    {
        // ── 0. Rate limiting: 10 req/min per IP (sliding window, Redis-backed) ─
        // FAIL-OPEN: if Redis is unavailable, log a warning and allow the
        // request through.  Rate limiting is a soft abuse-prevention measure —
        // it must never block legitimate financial transactions due to a cache
        // outage.  Financial correctness (balance checks, idempotency) is
        // enforced by MySQL, which remains authoritative regardless of Redis.
        try {
            $limiter = $this->transferCreationLimiter->create($request->getClientIp() ?? 'unknown');
            $limit   = $limiter->consume();

            if (!$limit->isAccepted()) {
                return new JsonResponse(
                    ['error' => ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests. Try again later.']],
                    Response::HTTP_TOO_MANY_REQUESTS,
                    [
                        'X-RateLimit-Limit'     => (string) $limit->getLimit(),
                        'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                        'Retry-After'           => (string) ($limit->getRetryAfter()->getTimestamp() - time()),
                    ],
                );
            }
        } catch (\Throwable $rateLimiterError) {
            $this->logger->warning('rate_limiter.unavailable', [
                'client_ip'     => $request->getClientIp() ?? 'unknown',
                'error_class'   => $rateLimiterError::class,
                'error_message' => $rateLimiterError->getMessage(),
            ]);
        }

        $body = json_decode($request->getContent(), associative: true);

        if (!is_array($body)) {
            return new JsonResponse([
                'error' => [
                    'code'    => 'INVALID_JSON',
                    'message' => 'Request body must be a valid JSON object.',
                ],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $req        = InitiateTransferRequest::fromArray($body);
        $violations = $this->validator->validate($req);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[] = [
                    'field'   => $v->getPropertyPath(),
                    'message' => $v->getMessage(),
                ];
            }

            return new JsonResponse([
                'error' => [
                    'code'       => 'VALIDATION_ERROR',
                    'message'    => 'Request validation failed.',
                    'violations' => $errors,
                ],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        // The idempotency key is passed into the command so InitiateTransferHandler
        // writes it atomically inside the DB transaction — closing the crash-after-commit
        // window where the HTTP cache is cold but money movement has already committed.
        $idempotencyKey = $request->attributes->get(IdempotencySubscriber::IDEMPOTENCY_KEY_ATTR);

        $dto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $req->sourceAccountId,
            destinationAccountId: $req->destinationAccountId,
            amountMinorUnits:     $req->amountMinorUnits,
            currency:             $req->currency,
            description:          $req->description,
            idempotencyKey:       is_string($idempotencyKey) ? $idempotencyKey : null,
        ));

        return new JsonResponse(
            ['data' => $dto->toArray()],
            JsonResponse::HTTP_CREATED,
            ['Location' => '/transfers/' . $dto->id],
        );
    }

    #[Route('/{id}', name: '_get', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function get(string $id): JsonResponse
    {
        // TransferNotFoundException (404) is handled by DomainExceptionListener
        $dto = ($this->findHandler)(new FindTransferByIdQuery($id));

        return new JsonResponse(['data' => $dto->toArray()]);
    }

    /**
     * List transfers, optionally filtered by status, with pagination.
     *
     * Query parameters:
     *   status   — one of: pending, processing, completed, failed, reversed
     *   page     — 1-based page number (default 1)
     *   per_page — items per page, 1–100 (default 25)
     */
    #[Route('', name: '_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        [$status, $page, $perPage, $errorResponse] = $this->parsePaginationParams($request);

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $dto = ($this->findByFiltersHandler)(new FindTransfersByFiltersQuery(
            status:    $status,
            accountId: null,
            page:      $page,
            perPage:   $perPage,
        ));

        return new JsonResponse(['data' => $dto->toArray()]);
    }

    /**
     * Reverse a completed transfer.
     *
     * ## Idempotency
     *   Include X-Idempotency-Key to make retries safe.  The IdempotencySubscriber
     *   hashes method + path + body so two POST /transfers/{id}/reverse calls
     *   with the same key return the cached REVERSED response on retry.
     *
     * ## Errors
     *   - 404  TRANSFER_NOT_FOUND        — unknown transfer ID
     *   - 409  INVALID_TRANSFER_STATE    — transfer is not COMPLETED (already REVERSED, FAILED, etc.)
     *   - 422  INSUFFICIENT_FUNDS        — destination account has been fully drained
     *   - 409  ACCOUNT_FROZEN            — source or destination account is frozen
     *   - 404  ACCOUNT_NOT_FOUND         — account deleted after the original transfer (data integrity issue)
     */
    #[Route(
        '/{id}/reverse',
        name: '_reverse',
        methods: ['POST'],
        requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
    )]
    public function reverse(string $id): JsonResponse
    {
        // Domain exceptions bubble to DomainExceptionListener — no try/catch here.
        $dto = ($this->reverseHandler)(new ReverseTransferCommand($id));

        return new JsonResponse(['data' => $dto->toArray()]);
    }

    /**
     * Parse and validate pagination + status query parameters shared by list endpoints.
     *
     * @return array{0: string|null, 1: int, 2: int, 3: JsonResponse|null}
     *   [status, page, perPage, errorResponse]  — errorResponse is non-null on validation failure
     */
    private function parsePaginationParams(Request $request): array
    {
        $statusParam  = $request->query->get('status');
        $pageParam    = (int) ($request->query->get('page', 1));
        $perPageParam = (int) ($request->query->get('per_page', 25));

        $status = null;
        if ($statusParam !== null && $statusParam !== '') {
            $statusEnum = TransferStatus::tryFrom($statusParam);
            if ($statusEnum === null) {
                $valid = implode(', ', array_column(TransferStatus::cases(), 'value'));
                return [null, 1, 25, new JsonResponse([
                    'error' => [
                        'code'    => 'INVALID_STATUS',
                        'message' => sprintf('Invalid status "%s". Valid values: %s.', $statusParam, $valid),
                    ],
                ], JsonResponse::HTTP_BAD_REQUEST)];
            }
            $status = $statusEnum->value;
        }

        if ($pageParam < 1) {
            return [null, 1, 25, new JsonResponse([
                'error' => ['code' => 'INVALID_PAGE', 'message' => 'Page must be at least 1.'],
            ], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($perPageParam < 1 || $perPageParam > 100) {
            return [null, 1, 25, new JsonResponse([
                'error' => ['code' => 'INVALID_PER_PAGE', 'message' => 'per_page must be between 1 and 100.'],
            ], JsonResponse::HTTP_BAD_REQUEST)];
        }

        return [$status, $pageParam, $perPageParam, null];
    }
}
