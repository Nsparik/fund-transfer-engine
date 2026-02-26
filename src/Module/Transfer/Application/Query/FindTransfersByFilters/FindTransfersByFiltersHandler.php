<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Query\FindTransfersByFilters;

use App\Module\Transfer\Application\DTO\PaginatedTransfersDTO;
use App\Module\Transfer\Application\Port\TransferReadRepositoryInterface;
use App\Shared\Application\Port\TransferQueryPort;

/**
 * Returns a paginated list of transfers, optionally filtered by status or account.
 *
 * Implements TransferQueryPort (Shared) so that modules outside the Transfer
 * bounded context (e.g. AccountController) can inject the Shared port and be
 * decoupled from this concrete handler class.
 */
final class FindTransfersByFiltersHandler implements TransferQueryPort
{
    public function __construct(
        private readonly TransferReadRepositoryInterface $queryRepository,
    ) {}

    public function __invoke(FindTransfersByFiltersQuery $query): PaginatedTransfersDTO
    {
        return $this->findByFilters(
            status:    $query->status,
            accountId: $query->accountId,
            page:      $query->page,
            perPage:   $query->perPage,
        );
    }

    /**
     * {@inheritDoc}
     *
     * Page and perPage are clamped here (single authoritative clamping point)
     * so callers via both __invoke() and direct findByFilters() get consistent
     * boundary enforcement.
     */
    public function findByFilters(
        ?string $status    = null,
        ?string $accountId = null,
        int     $page      = 1,
        int     $perPage   = 25,
    ): PaginatedTransfersDTO {
        return $this->queryRepository->findByFilters(
            status:    $status,
            accountId: $accountId,
            page:      max(1, $page),
            perPage:   min(100, max(1, $perPage)),
        );
    }
}
