<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * LedgerEntryRecorderPort — cross-module port for writing ledger entries.
 *
 * Implemented by DbalLedgerRepository (Ledger module).
 * Injected by InitiateTransferHandler and ReverseTransferHandler (Transfer module).
 *
 * ## Why this port exists
 *   The Transfer module must not import Ledger domain types (LedgerEntry, EntryType, etc.)
 *   — that would create an inter-module dependency that prevents future microservice
 *   extraction.  This port exposes only primitive types, keeping both modules fully
 *   decoupled.  When the Ledger module is extracted, swap this alias in services.yaml
 *   to an HTTP/gRPC adapter — zero changes to the Transfer handlers.
 *
 * ## Atomicity contract
 *   MUST be called INSIDE an active DB transaction.  Both ledger entries are written
 *   atomically alongside the transfer state change and account balance updates.
 *   If the transaction rolls back (e.g. insufficient funds), no ledger entries are written.
 *
 * ## Idempotency
 *   The implementation uses INSERT IGNORE backed by a UNIQUE constraint on
 *   (account_id, transfer_id, entry_type).  Calling this method twice with the
 *   same arguments is safe — the second call writes nothing.
 */
interface LedgerEntryRecorderPort
{
    /**
     * Write two ledger entries (DEBIT + CREDIT) for a completed transfer or reversal.
     *
     * @param string $transferType 'transfer' | 'reversal'
     */
    public function recordTransferEntries(
        string             $sourceAccountId,
        string             $destinationAccountId,
        string             $transferId,
        string             $transferType,
        int                $amountMinorUnits,
        string             $currency,
        int                $sourceBalanceAfterMinorUnits,
        int                $destinationBalanceAfterMinorUnits,
        \DateTimeImmutable $occurredAt,
    ): void;

    /**
     * Write a single CREDIT ledger entry for a bootstrap (opening-balance) event.
     *
     * Used exclusively by CreateAccountHandler when an account is opened with a
     * non-zero initial balance.  Only ONE entry is written (the CREDIT on the
     * real account) because the synthetic counterparty
     * ('00000000-0000-7000-8000-000000000000') does not exist in the accounts
     * table and therefore cannot satisfy the FK constraint on
     * ledger_entries.account_id → accounts.id that was added in
     * Version20260226000001AddForeignKeyConstraints.
     *
     * The counterparty UUID is stored in the counterparty_account_id column
     * which is intentionally NOT FK-constrained (see migration docblock).
     */
    public function recordBootstrapCreditEntry(
        string             $accountId,
        string             $systemCounterpartyId,
        string             $transferId,
        int                $amountMinorUnits,
        string             $currency,
        \DateTimeImmutable $occurredAt,
    ): void;
}
