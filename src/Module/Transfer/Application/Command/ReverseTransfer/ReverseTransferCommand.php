<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Command\ReverseTransfer;

/**
 * Command: request to reverse a completed fund transfer.
 *
 * Plain value object — no framework dependencies.
 * Created by the UI layer (HTTP controller) and passed to ReverseTransferHandler.
 *
 * Only COMPLETED transfers can be reversed.  Attempting to reverse a transfer
 * in any other state raises InvalidTransferStateException (→ HTTP 409).
 *
 * The reversal performs a double-entry correction in the same atomic transaction:
 *   - Credit source account (funds returned)
 *   - Debit destination account (funds reclaimed)
 *
 * If the destination account has insufficient funds the reversal is rejected
 * with InsufficientFundsException (→ HTTP 422).  The transfer remains COMPLETED
 * and no partial state is saved.
 */
final readonly class ReverseTransferCommand
{
    /**
     * @param string $transferId UUID of the COMPLETED transfer to reverse
     */
    public function __construct(
        public readonly string $transferId,
    ) {}
}
