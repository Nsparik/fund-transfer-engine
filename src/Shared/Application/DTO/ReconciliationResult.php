<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/**
 * Per-account result of a balance reconciliation pass.
 *
 * Computed by ReconcileBalancesService from a ReconciliationRow.
 * Immutable — all fields are set once in the constructor.
 *
 * ## Statuses
 *
 *   match           — ledger snapshot matches live account balance (healthy), OR
 *                     account has zero balance with no ledger entries (never transacted)
 *
 *   mismatch        — a ledger entry exists but its balance_after_minor_units differs from
 *                     the live accounts.balance_minor_units (CRITICAL — data integrity violation)
 *
 *   no_ledger_entry — no ledger entry exists yet, but the account has a non-zero balance;
 *                     expected for accounts created with an initialBalanceMinorUnits > 0,
 *                     which is set directly on the account row without going through the
 *                     transfer pipeline
 *
 * ## diffMinorUnits
 *
 *   match            → 0
 *   mismatch         → accountBalance − ledgerBalance  (positive = account has more, negative = less)
 *   no_ledger_entry  → accountBalance                 (distance from zero / unrecorded amount)
 */
final class ReconciliationResult
{
    public const STATUS_MATCH              = 'match';
    public const STATUS_MISMATCH           = 'mismatch';
    public const STATUS_NO_LEDGER_ENTRY    = 'no_ledger_entry';
    /**
     * SUM(credits − debits) ≠ accounts.balance_minor_units.
     *
     * The last-entry snapshot looks correct (STATUS_MATCH by the snapshot check)
     * but the running sum of amount_minor_units across all entries disagrees.
     * This catches a corrupt amount_minor_units on any non-final ledger entry —
     * a gap the snapshot check alone cannot detect.
     */
    public const STATUS_LEDGER_SUM_MISMATCH = 'ledger_sum_mismatch';

    /** @var 'match'|'mismatch'|'no_ledger_entry' */
    public readonly string $status;

    /**
     * Signed difference: accountBalance − ledgerBalance.
     *
     *   match           → always 0 (even when ledgerBalance is null and accountBalance is 0)
     *   mismatch        → accountBalance − ledgerBalance  (positive = account has more, negative = less)
     *   no_ledger_entry → accountBalance (amount not yet recorded in the ledger)
     */
    public readonly int $diffMinorUnits;

    public function __construct(
        public readonly string $accountId,
        public readonly string $currency,
        public readonly int    $accountBalance,
        public readonly ?int   $ledgerBalance,
        public readonly ?int   $ledgerComputedBalance = null,
    ) {

        if ($ledgerBalance === null) {
            if ($accountBalance === 0) {
                $this->status         = self::STATUS_MATCH;
                $this->diffMinorUnits = 0;
            } else {
                $this->status         = self::STATUS_NO_LEDGER_ENTRY;
                $this->diffMinorUnits = $accountBalance;
            }
        } else {
            $diff                 = $accountBalance - $ledgerBalance;
            $this->status         = $diff === 0 ? self::STATUS_MATCH : self::STATUS_MISMATCH;
            $this->diffMinorUnits = $diff;
        }

        // Even when the snapshot check passes (STATUS_MATCH), verify that
        // SUM(credit amounts) − SUM(debit amounts) also equals the live balance.
        // A mismatch here means a non-final ledger entry has a corrupt
        // amount_minor_units value — invisible to the snapshot check alone.
        if (
            $ledgerComputedBalance !== null
            && $this->status === self::STATUS_MATCH
            && $ledgerComputedBalance !== $accountBalance
        ) {
            $this->status         = self::STATUS_LEDGER_SUM_MISMATCH;
            $this->diffMinorUnits = $accountBalance - $ledgerComputedBalance;
        }
    }

    /**
     * Returns true only for STATUS_MATCH — the account is fully reconciled.
     */
    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_MATCH;
    }
}
