<?php

declare(strict_types=1);

namespace App\Module\Ledger\Domain\ValueObject;

/**
 * EntryType — the two sides of a double-entry ledger leg.
 *
 * DEBIT  — funds leaving an account (source account on a transfer,
 *           destination account on a reversal).
 * CREDIT — funds entering an account (destination account on a transfer,
 *           source account on a reversal).
 *
 * The 'transfer' / 'reversal' distinction is carried separately in
 * LedgerEntry::$transferType so statement consumers can render the
 * correct narrative without re-querying the transfers table.
 */
enum EntryType: string
{
    case DEBIT  = 'debit';
    case CREDIT = 'credit';
}
