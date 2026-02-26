<?php

declare(strict_types=1);

namespace App\Module\Ledger\Domain\Repository;

use App\Module\Ledger\Domain\Model\LedgerEntry;
use App\Module\Ledger\Domain\Model\LedgerPage;
use App\Module\Ledger\Domain\ValueObject\AccountId;

/**
 * LedgerRepositoryInterface — the Domain port for ledger persistence.
 *
 * Implementations live in Infrastructure/Persistence/.
 * The Domain layer depends only on this interface — never on DBAL.
 */
interface LedgerRepositoryInterface
{
    /**
     * Persist a single LedgerEntry row.
     *
     * Uses INSERT IGNORE (idempotent) — the UNIQUE constraint on
     * (account_id, transfer_id, entry_type) means a duplicate write
     * silently succeeds without corrupting the ledger.
     */
    public function save(LedgerEntry $entry): void;

    /**
     * Return paginated entries for an account within a UTC date range,
     * ordered by occurred_at DESC (most recent first).
     *
     * Used by the statement query handler.
     *
     * @param AccountId          $accountId  The account to query
     * @param \DateTimeImmutable $from       Range start (inclusive, UTC)
     * @param \DateTimeImmutable $to         Range end   (inclusive, UTC)
     * @param int                $page       1-based page number
     * @param int                $perPage    Rows per page (max enforced by caller)
     */
    public function findByAccountIdAndDateRange(
        AccountId          $accountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int                $page,
        int                $perPage,
    ): LedgerPage;

    /**
     * Return the most recent LedgerEntry for $accountId with
     * occurred_at STRICTLY BEFORE $before.
     *
     * Used to compute the opening balance for a statement:
     *   openingBalance = lastEntryBefore($from)->balanceAfterMinorUnits
     *
     * Returns null when there is no prior activity (balance = 0 before the range).
     */
    public function findLastEntryBefore(
        AccountId          $accountId,
        \DateTimeImmutable $before,
    ): ?LedgerEntry;

    /**
     * Return the most recent LedgerEntry for $accountId with
     * occurred_at AT OR BEFORE $at (inclusive — WHERE occurred_at <= :at).
     *
     * Used to compute the CLOSING balance for a statement range:
     *   closingBalance = lastEntryAtOrBefore($to)->balanceAfterMinorUnits
     *
     * Distinct from findLastEntryBefore() (strict <) which is used only for
     * opening balance (entries before the range start, not including $from).
     *
     * Returns null when there is no entry at or before $at.
     */
    public function findLastEntryAtOrBefore(
        AccountId          $accountId,
        \DateTimeImmutable $at,
    ): ?LedgerEntry;

    /**
     * Return the most recent LedgerEntry for $accountId (no date filter).
     *
     * Used by the reconciliation CLI to compare the ledger's final
     * balance snapshot against the live accounts.balance_minor_units value.
     */
    public function findLastEntryForAccount(AccountId $accountId): ?LedgerEntry;
}
