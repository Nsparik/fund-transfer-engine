<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Port;

use App\Module\Transfer\Application\DTO\PaginatedTransfersDTO;

/**
 * Read-only (query-side) repository port for the Transfer module.
 *
 * Kept separate from TransferRepositoryInterface (Domain) to preserve
 * Domain-layer purity: the Domain port must not import Application-layer types
 * such as DTOs.  This Application-layer port is free to reference
 * PaginatedTransfersDTO.
 *
 * CQRS split:
 *   TransferRepositoryInterface (Domain)  — command side: save, findById, ...
 *   TransferReadRepositoryInterface (here) — query side:  findByFilters
 *
 * The infrastructure adapter (DbalTransferRepository) implements both.
 */
interface TransferReadRepositoryInterface
{
    /**
     * Return a paginated, optionally filtered list of transfers.
     *
     * @param string|null $status    Filter by TransferStatus value (e.g. 'completed'). Null = all.
     * @param string|null $accountId Return transfers where source OR destination matches. Null = all.
     * @param int         $page      1-based page number (must be ≥ 1)
     * @param int         $perPage   Page size (must be between 1 and 100)
     */
    public function findByFilters(
        ?string $status    = null,
        ?string $accountId = null,
        int     $page      = 1,
        int     $perPage   = 25,
    ): PaginatedTransfersDTO;
}
