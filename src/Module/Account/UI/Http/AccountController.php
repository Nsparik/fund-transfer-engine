<?php

declare(strict_types=1);

namespace App\Module\Account\UI\Http;

use App\Module\Account\Application\Command\CloseAccount\CloseAccountCommand;
use App\Module\Account\Application\Command\CloseAccount\CloseAccountHandler;
use App\Module\Account\Application\Command\CreateAccount\CreateAccountCommand;
use App\Module\Account\Application\Command\CreateAccount\CreateAccountHandler;
use App\Module\Account\Application\Command\FreezeAccount\FreezeAccountCommand;
use App\Module\Account\Application\Command\FreezeAccount\FreezeAccountHandler;
use App\Module\Account\Application\Command\UnfreezeAccount\UnfreezeAccountCommand;
use App\Module\Account\Application\Command\UnfreezeAccount\UnfreezeAccountHandler;
use App\Module\Account\Application\Query\FindAccountById\FindAccountByIdHandler;
use App\Module\Account\Application\Query\FindAccountById\FindAccountByIdQuery;
use App\Module\Transfer\Domain\Model\TransferStatus;
use App\Shared\Application\Port\TransferQueryPort;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * REST controller for the Account resource.
 *
 * ## Response envelope
 *
 *   Success:
 *     { "data": { ...AccountDTO fields... } }
 *
 *   Validation error (400):
 *     { "error": { "code": "VALIDATION_ERROR", "message": "...", "violations": [...] } }
 *
 *   Not found (404) / Domain error (409, 422):
 *     { "error": { "code": "ACCOUNT_NOT_FOUND", "message": "..." } }
 *     — produced by DomainExceptionListener
 *
 * ## Endpoints
 *   POST   /accounts             — open a new account
 *   GET    /accounts/{id}        — fetch an account by ID
 *   POST   /accounts/{id}/freeze   — freeze an active account
 *   POST   /accounts/{id}/unfreeze — reinstate a frozen account
 *   POST   /accounts/{id}/close    — permanently close an active or frozen account
 */
#[Route('/accounts', name: 'accounts')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly CreateAccountHandler            $createHandler,
        private readonly FindAccountByIdHandler          $findHandler,
        private readonly FreezeAccountHandler            $freezeHandler,
        private readonly UnfreezeAccountHandler          $unfreezeHandler,
        private readonly CloseAccountHandler             $closeHandler,
        private readonly TransferQueryPort               $transferQueryPort,
        private readonly ValidatorInterface              $validator,
        private readonly RateLimiterFactory              $accountCreationLimiter,
        private readonly LoggerInterface                $logger,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('', name: '_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // ── Rate limiting: 20 req/min per IP (sliding window) ────────────────
        // FAIL-OPEN: a Redis outage must never prevent account creation.
        try {
            $limit = $this->accountCreationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$limit->isAccepted()) {
                return new JsonResponse(
                    ['error' => ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many account creation requests. Try again later.']],
                    Response::HTTP_TOO_MANY_REQUESTS,
                );
            }
        } catch (\Throwable $rateLimiterError) {
            // Redis is unavailable — fail open so account creation is never
            // blocked by a cache outage.  Log the error so on-call engineers
            // are alerted and can restore the rate-limiter pool.
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

        $req        = CreateAccountRequest::fromArray($body);
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

        $dto = ($this->createHandler)(new CreateAccountCommand(
            accountId:                $req->accountId,
            ownerName:                $req->ownerName,
            currency:                 $req->currency,
            initialBalanceMinorUnits: $req->initialBalanceMinorUnits,
        ));

        return new JsonResponse(
            ['data' => $dto->toArray()],
            JsonResponse::HTTP_CREATED,
            ['Location' => '/accounts/' . $dto->id],
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /accounts/{id}
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: '_get', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function get(string $id): JsonResponse
    {
        $dto = ($this->findHandler)(new FindAccountByIdQuery($id));

        return new JsonResponse(['data' => $dto->toArray()]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts/{id}/freeze
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/freeze', name: '_freeze', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function freeze(string $id): JsonResponse
    {
        $dto = ($this->freezeHandler)(new FreezeAccountCommand($id));

        return new JsonResponse(['data' => $dto->toArray()]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts/{id}/unfreeze
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/unfreeze', name: '_unfreeze', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function unfreeze(string $id): JsonResponse
    {
        $dto = ($this->unfreezeHandler)(new UnfreezeAccountCommand($id));

        return new JsonResponse(['data' => $dto->toArray()]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts/{id}/close
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/close', name: '_close', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function close(string $id): JsonResponse
    {
        $dto = ($this->closeHandler)(new CloseAccountCommand($id));

        return new JsonResponse(['data' => $dto->toArray()]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /accounts/{id}/transfers?status=&page=&per_page=
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * List transfers for an account (source or destination), with optional
     * status filter and pagination.
     *
     * Query parameters:
     *   status   — one of: pending, processing, completed, failed, reversed
     *   page     — 1-based page number (default 1)
     *   per_page — items per page, 1–100 (default 25)
     */
    #[Route('/{id}/transfers', name: '_transfers', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function transfers(string $id, Request $request): JsonResponse
    {
        $statusParam  = $request->query->get('status');
        $pageParam    = (int) ($request->query->get('page', 1));
        $perPageParam = (int) ($request->query->get('per_page', 25));

        // Derive valid statuses directly from the TransferStatus enum so that
        // adding a new case is automatically reflected here — no manual sync.
        // Importing TransferStatus in the Account UI controller is intentional:
        // the module-boundary rule guards Domain and Application layers;
        // HTTP controllers are presentation infrastructure and may reference
        // cross-module enum values for input validation.
        $validStatuses = array_map(
            static fn(TransferStatus $s): string => $s->value,
            TransferStatus::cases(),
        );
        $status = null;
        if ($statusParam !== null && $statusParam !== '') {
            if (!in_array($statusParam, $validStatuses, strict: true)) {
                return new JsonResponse([
                    'error' => [
                        'code'    => 'INVALID_STATUS',
                        'message' => sprintf('Invalid status "%s". Valid values: %s.', $statusParam, implode(', ', $validStatuses)),
                    ],
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
            $status = $statusParam;
        }

        if ($pageParam < 1) {
            return new JsonResponse([
                'error' => ['code' => 'INVALID_PAGE', 'message' => 'Page must be at least 1.'],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($perPageParam < 1 || $perPageParam > 100) {
            return new JsonResponse([
                'error' => ['code' => 'INVALID_PER_PAGE', 'message' => 'per_page must be between 1 and 100.'],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Guard: verify the account exists before querying its transfers.
        // FindAccountByIdHandler throws AccountNotFoundException → HTTP 404
        // when the account is not in the database, preventing a silent 200
        // with an empty list for non-existent account IDs.
        ($this->findHandler)(new FindAccountByIdQuery($id));

        $dto = $this->transferQueryPort->findByFilters(
            status:    $status,
            accountId: $id,
            page:      $pageParam,
            perPage:   $perPageParam,
        );

        return new JsonResponse(['data' => $dto->toArray()]);
    }
}
