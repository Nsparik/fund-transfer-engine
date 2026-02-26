<?php

declare(strict_types=1);

namespace App\Module\Ledger\Domain\Model;

/**
 * LedgerPage — a paginated slice of LedgerEntry records.
 *
 * Returned by LedgerRepositoryInterface::findByAccountIdAndDateRange().
 * Plain value object — no framework dependencies.
 */
final readonly class LedgerPage
{
    /**
     * @param list<LedgerEntry> $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int   $total,
        public readonly int   $page,
        public readonly int   $perPage,
    ) {}

    public function getTotalPages(): int
    {
        if ($this->perPage <= 0) {
            return 0;
        }

        // Always report at least 1 page even for empty results — consistent with
        // standard pagination APIs ("page 1 of 1" for an empty set is less
        // confusing to clients than "page 1 of 0").
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
