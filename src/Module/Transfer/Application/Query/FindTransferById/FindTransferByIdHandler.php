<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Query\FindTransferById;

use App\Module\Transfer\Application\DTO\TransferDTO;
use App\Module\Transfer\Domain\Exception\TransferNotFoundException;
use App\Module\Transfer\Domain\Repository\TransferRepositoryInterface;
use App\Module\Transfer\Domain\ValueObject\TransferId;

/**
 * Returns the TransferDTO for a given transfer ID.
 *
 * @throws TransferNotFoundException when the ID does not exist OR is not a valid UUID
 */
final class FindTransferByIdHandler
{
    public function __construct(
        private readonly TransferRepositoryInterface $transfers,
    ) {}

    public function __invoke(FindTransferByIdQuery $query): TransferDTO
    {
        try {
            $id = TransferId::fromString($query->transferId);
        } catch (\InvalidArgumentException) {
            // Malformed UUID → treat as not-found (avoids leaking internal details)
            throw new TransferNotFoundException(
                sprintf('Transfer "%s" not found.', $query->transferId)
            );
        }

        return TransferDTO::fromTransfer(
            $this->transfers->getById($id)
        );
    }
}
