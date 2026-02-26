<?php

declare(strict_types=1);

namespace App\Module\Ledger\Application\DTO;

/**
 * AccountStatementDTO — the full account statement response payload.
 *
 * Contains the opening/closing balance for the requested date range,
 * all movement lines (paginated), and pagination metadata.
 *
 * Opening balance:
 *   The balance snapshot immediately BEFORE $from.
 *   Derived from the last LedgerEntry with occurred_at < $from.
 *   Zero when the account had no prior activity.
 *
 * Closing balance:
 *   The balance snapshot of the last movement within the range.
 *   Equals opening balance when movements is empty.
 */
final readonly class AccountStatementDTO
{
    /**
     * @param list<StatementLineDTO> $movements
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $ownerName,
        public readonly string $currency,
        public readonly string $from,                       // ISO 8601 UTC
        public readonly string $to,                         // ISO 8601 UTC
        public readonly int    $openingBalanceMinorUnits,
        public readonly int    $closingBalanceMinorUnits,
        public readonly array  $movements,
        public readonly int    $totalMovements,
        public readonly int    $page,
        public readonly int    $perPage,
        public readonly int    $totalPages,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accountId'                  => $this->accountId,
            'ownerName'                  => $this->ownerName,
            'currency'                   => $this->currency,
            'from'                       => $this->from,
            'to'                         => $this->to,
            'openingBalanceMinorUnits'   => $this->openingBalanceMinorUnits,
            'closingBalanceMinorUnits'   => $this->closingBalanceMinorUnits,
            'movements'                  => array_map(
                fn (StatementLineDTO $line) => $line->toArray(),
                $this->movements,
            ),
            'pagination'                 => [
                'page'       => $this->page,
                'perPage'    => $this->perPage,
                'total'      => $this->totalMovements,
                'totalPages' => $this->totalPages,
            ],
        ];
    }
}
