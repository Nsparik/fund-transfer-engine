<?php

declare(strict_types=1);

namespace App\Module\Ledger\Application\Query\FindAccountStatement;

/**
 * Query to retrieve a paginated account statement for a given date range.
 *
 * @param string $accountId  UUID of the account
 * @param string $from       Range start — ISO 8601 UTC (e.g. "2026-01-01T00:00:00Z")
 * @param string $to         Range end   — ISO 8601 UTC (e.g. "2026-01-31T23:59:59Z")
 * @param int    $page       1-based page number (default 1)
 * @param int    $perPage    Items per page, 1–100 (default 50)
 */
final readonly class FindAccountStatementQuery
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $from,
        public readonly string $to,
        public readonly int    $page    = 1,
        public readonly int    $perPage = 50,
    ) {}
}
