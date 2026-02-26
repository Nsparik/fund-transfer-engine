<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\DTO;

use App\Module\Transfer\Domain\Model\Transfer;

/**
 * Read model produced from a Transfer aggregate for external consumers
 * (API responses, CLI output, test assertions).
 *
 * Only primitive scalar values — no domain objects leak past this boundary.
 */
final readonly class TransferDTO
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $reference,
        public readonly string  $sourceAccountId,
        public readonly string  $destinationAccountId,
        public readonly int     $amountMinorUnits,
        public readonly string  $currency,
        public readonly ?string $description,
        public readonly string  $status,
        public readonly ?string $failureCode,
        public readonly ?string $failureReason,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly ?string $completedAt,
        public readonly ?string $failedAt,
        public readonly ?string $reversedAt,
    ) {}

    /**
     * Serialise to a plain associative array for JSON responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                   => $this->id,
            'reference'            => $this->reference,
            'sourceAccountId'      => $this->sourceAccountId,
            'destinationAccountId' => $this->destinationAccountId,
            'amountMinorUnits'     => $this->amountMinorUnits,
            'currency'             => $this->currency,
            'description'          => $this->description,
            'status'               => $this->status,
            'failureCode'          => $this->failureCode,
            'failureReason'        => $this->failureReason,
            'createdAt'            => $this->createdAt,
            'updatedAt'            => $this->updatedAt,
            'completedAt'          => $this->completedAt,
            'failedAt'             => $this->failedAt,
            'reversedAt'           => $this->reversedAt,
        ];
    }

    /**
     * Build a DTO from a live aggregate (e.g. immediately after creation).
     */
    public static function fromTransfer(Transfer $transfer): self
    {
        return new self(
            id:                   $transfer->getId()->toString(),
            reference:            $transfer->getReference()->toString(),
            sourceAccountId:      $transfer->getSourceAccountId()->toString(),
            destinationAccountId: $transfer->getDestinationAccountId()->toString(),
            amountMinorUnits:     $transfer->getAmount()->getAmountMinorUnits(),
            currency:             $transfer->getAmount()->getCurrency(),
            description:          $transfer->getDescription(),
            status:               $transfer->getStatus()->value,
            failureCode:          $transfer->getFailureCode(),
            failureReason:        $transfer->getFailureReason(),
            createdAt:            $transfer->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt:            $transfer->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            completedAt:          $transfer->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            failedAt:             $transfer->getFailedAt()?->format(\DateTimeInterface::ATOM),
            reversedAt:           $transfer->getReversedAt()?->format(\DateTimeInterface::ATOM),
        );
    }
}
