<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\DTO;

/**
 * Paginated list of TransferDTOs, returned by the FindTransfersByFilters query.
 */
final readonly class PaginatedTransfersDTO
{
    /**
     * @param list<TransferDTO> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int   $total,
        public readonly int   $page,
        public readonly int   $perPage,
        public readonly int   $totalPages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items'      => array_map(static fn (TransferDTO $dto) => $dto->toArray(), $this->items),
            'total'      => $this->total,
            'page'       => $this->page,
            'perPage'    => $this->perPage,
            'totalPages' => $this->totalPages,
        ];
    }
}
