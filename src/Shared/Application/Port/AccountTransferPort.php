<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Exception\AccountNotFoundForTransferException;
use App\Shared\Domain\Exception\AccountRuleViolationException;

/**
 * Outgoing port used by the Transfer module to execute the Account-side
 * of a double-entry transfer (debit source, credit destination).
 *
 * The Transfer module depends only on this interface — never on Account
 * domain types — keeping the two bounded contexts independently extractable.
 *
 * MUST be called inside an active DB transaction (started by the Transfer handler).
 *
 * Returns a DoubleEntryResult containing post-operation balance snapshots and
 * the domain events raised by both Account aggregates so the Transfer handler
 * can write them to the outbox after the transaction commits.
 */
interface AccountTransferPort
{
    /**
     * Execute the double-entry accounting for a transfer:
     *   - Lock both accounts FOR UPDATE in deadlock-safe order.
     *   - Debit  the source account.
     *   - Credit the destination account.
     *   - Persist both accounts.
     *   - Return balance snapshots + domain events from both Account aggregates.
     *
     * @param string $transferType  'transfer' for original transfers, 'reversal' for reversals.
     *                              Propagated to AccountDebited/AccountCredited events so that
     *                              downstream ledger consumers can distinguish entry types.
     *
     * @throws AccountNotFoundForTransferException when source or destination account does not exist
     * @throws AccountRuleViolationException        on business-rule violations (frozen, closed, insufficient funds, currency mismatch)
     */
    public function executeDoubleEntry(
        string $sourceAccountId,
        string $destinationAccountId,
        int    $amountMinorUnits,
        string $currency,
        string $transferId,
        string $transferType = 'transfer',
    ): DoubleEntryResult;
}
