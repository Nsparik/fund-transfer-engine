<?php

declare(strict_types=1);

namespace App\Module\Ledger\Application\DTO;

use App\Module\Ledger\Domain\Model\LedgerEntry;

/**
 * StatementLineDTO — a single enriched movement line in an account statement.
 *
 * Corresponds directly to one LedgerEntry row but uses primitive types
 * so consumers (controllers, API serializers) need no Ledger domain imports.
 */
final readonly class StatementLineDTO
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $type,                      // 'debit' | 'credit'
        public readonly string $transferType,              // 'transfer' | 'reversal' | 'bootstrap'
        public readonly int    $amountMinorUnits,
        public readonly string $currency,
        public readonly int    $balanceAfterMinorUnits,
        public readonly string $transferId,
        public readonly string $counterpartyAccountId,
        public readonly string $occurredAt,                // ISO 8601 UTC
    ) {}

    public static function fromLedgerEntry(LedgerEntry $entry): self
    {
        return new self(
            entryId:                $entry->getId()->toString(),
            type:                   $entry->getEntryType()->value,
            transferType:           $entry->getTransferType(),
            amountMinorUnits:       $entry->getAmountMinorUnits(),
            currency:               $entry->getCurrency(),
            balanceAfterMinorUnits: $entry->getBalanceAfterMinorUnits(),
            transferId:             $entry->getTransferId(),
            counterpartyAccountId:  $entry->getCounterpartyAccountId()->toString(),
            occurredAt:             $entry->getOccurredAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entryId'                => $this->entryId,
            'type'                   => $this->type,
            'transferType'           => $this->transferType,
            'amountMinorUnits'       => $this->amountMinorUnits,
            'currency'               => $this->currency,
            'balanceAfterMinorUnits' => $this->balanceAfterMinorUnits,
            'transferId'             => $this->transferId,
            'counterpartyAccountId'  => $this->counterpartyAccountId,
            'occurredAt'             => $this->occurredAt,
        ];
    }
}
