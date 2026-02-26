<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/**
 * Raw row returned by ReconciliationRepositoryInterface::findAllForReconciliation().
 *
 * Carries one account's current live balance alongside the most recent
 * ledger snapshot balance, if any.  This is a plain data-transfer object —
 * no business logic, no validation.  Passed into ReconcileBalancesService
 * for comparison and status assignment.
 *
 * ledgerBalance is null when the account has no ledger entries at all.
 */
final readonly class ReconciliationRow
{
    public function __construct(
        public string $accountId,
        public string $currency,
        public int    $accountBalance,
        public ?int   $ledgerBalance,
        /**
         * SUM(credit amounts) − SUM(debit amounts) across all ledger
         * entries for this account.  Null when the account has no ledger entries.
         *
         * A mismatch between this value and accountBalance means at least one
         * ledger entry has a corrupt amount_minor_units — not detectable by
         * the snapshot-only (last balance_after) check alone.
         */
        public ?int   $ledgerComputedBalance = null,
    ) {}
}
