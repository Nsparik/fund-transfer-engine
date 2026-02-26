<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Query\FindTransfersByFilters;

/**
 * Query to retrieve a paginated, optionally filtered list of transfers.
 *
 * All parameters are optional — omitting them returns all transfers (paged).
 *
 * @param string|null $status    Filter by TransferStatus value (e.g. 'completed')
 * @param string|null $accountId Return only transfers where this account is source or destination
 * @param int         $page      1-based page number (min 1)
 * @param int         $perPage   Items per page (min 1, max 100)
 */
final readonly class FindTransfersByFiltersQuery
{
    public function __construct(
        public readonly ?string $status    = null,
        public readonly ?string $accountId = null,
        public readonly int     $page      = 1,
        public readonly int     $perPage   = 25,
    ) {}
}
