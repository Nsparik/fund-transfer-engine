<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Module\Transfer\Application\DTO\PaginatedTransfersDTO;

/**
 * Anti-corruption-layer port: allows Account module (and any future module)
 * to query Transfer history without importing Transfer Application handlers
 * or domain types directly.
 *
 * ## Rationale
 *   In a modular monolith designed for microservice extraction, Account must
 *   not depend on Transfer's concrete handler or query objects.  This Shared
 *   port defines the contract; the binding in services.yaml wires it to the
 *   Transfer module's FindTransfersByFiltersHandler at runtime.
 *
 * ## Microservice extraction path
 *   When Transfer becomes a separate service, swap the binding in services.yaml
 *   to an HTTP/gRPC adapter — zero changes to Account module UI layer.
 *
 * ## Note on PaginatedTransfersDTO
 *   PaginatedTransfersDTO is a Transfer Application DTO (a plain data carrier
 *   with no domain logic).  Referencing it from Shared Application Port is
 *   acceptable in a modular monolith; the Shared layer is the designated
 *   integration point between bounded contexts.
 */
interface TransferQueryPort
{
    /**
     * Return a paginated, optionally filtered list of transfers.
     *
     * @param string|null $status    Filter by TransferStatus value (e.g. 'completed'). Null = all.
     * @param string|null $accountId Return transfers where source OR destination matches. Null = all.
     * @param int         $page      1-based page number (clamped to ≥ 1 by implementation)
     * @param int         $perPage   Page size (clamped to 1–100 by implementation)
     */
    public function findByFilters(
        ?string $status    = null,
        ?string $accountId = null,
        int     $page      = 1,
        int     $perPage   = 25,
    ): PaginatedTransfersDTO;
}
