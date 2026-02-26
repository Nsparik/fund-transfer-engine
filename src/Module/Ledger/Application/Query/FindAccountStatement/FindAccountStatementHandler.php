<?php

declare(strict_types=1);

namespace App\Module\Ledger\Application\Query\FindAccountStatement;

use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Repository\AccountRepositoryInterface;
use App\Module\Account\Domain\ValueObject\AccountId as AccountDomainId;
use App\Module\Ledger\Application\DTO\AccountStatementDTO;
use App\Module\Ledger\Application\DTO\StatementLineDTO;
use App\Module\Ledger\Domain\Exception\InvalidDateRangeException;
use App\Module\Ledger\Domain\Repository\LedgerRepositoryInterface;
use App\Module\Ledger\Domain\ValueObject\AccountId as LedgerAccountId;
use Psr\Log\LoggerInterface;

/**
 * Handles FindAccountStatementQuery.
 *
 * ## What it does
 *   1. Validates the requested date range (parse, ordering, max 366-day span).
 *   2. Verifies the account exists (throws AccountNotFoundException → 404).
 *   3. Computes the opening balance: balance_after_minor_units of the last
 *      LedgerEntry with occurred_at STRICTLY BEFORE $from.
 *      Zero when the account has no prior activity.
 *   4. Fetches paginated movements within [$from, $to].
 *   5. Derives closing balance: balance_after of the last movement in the page
 *      set, or the opening balance when there are no movements in the range.
 *   6. Returns AccountStatementDTO.
 *
 * ## No SUM() aggregation
 *   Opening and closing balances are read directly from the stored
 *   balance_after_minor_units snapshot — O(1) index seek, not a full scan.
 *
 * ## Cross-module dependency
 *   This handler imports AccountRepositoryInterface from the Account module
 *   to verify account existence and fetch ownerName/currency for the response.
 *   This is acceptable in the Modular Monolith phase.  When the Account module
 *   is extracted, replace with an HTTP/gRPC call via an ACL port.
 */
final class FindAccountStatementHandler
{
    private const MAX_RANGE_DAYS = 366;

    public function __construct(
        private readonly LedgerRepositoryInterface  $ledger,
        private readonly AccountRepositoryInterface $accounts,
        private readonly LoggerInterface            $logger,
    ) {}

    /**
     * @throws InvalidDateRangeException  on invalid / out-of-order / too-wide date range
     * @throws AccountNotFoundException   when the account does not exist
     * @throws \InvalidArgumentException  on malformed account UUID
     */
    public function __invoke(FindAccountStatementQuery $query): AccountStatementDTO
    {
        // ── 1. Parse and validate the date range ─────────────────────────────
        $from = $this->parseDate($query->from, 'from');
        $to   = $this->parseDate($query->to, 'to');

        if ($from >= $to) {
            throw new InvalidDateRangeException(
                sprintf(
                    'Invalid date range: "from" (%s) must be strictly before "to" (%s).',
                    $query->from,
                    $query->to,
                ),
            );
        }

        $diffDays = (int) $from->diff($to)->days;
        if ($diffDays > self::MAX_RANGE_DAYS) {
            throw new InvalidDateRangeException(
                sprintf(
                    'Date range too wide: %d days requested, maximum is %d days.',
                    $diffDays,
                    self::MAX_RANGE_DAYS,
                ),
            );
        }

        // ── 2. Verify the account exists ──────────────────────────────────────
        $account = $this->accounts->getById(AccountDomainId::fromString($query->accountId));

        $ledgerAccountId = LedgerAccountId::fromString($query->accountId);

        // ── 3. Compute opening balance ────────────────────────────────────────
        $lastBefore     = $this->ledger->findLastEntryBefore($ledgerAccountId, $from);
        $openingBalance = $lastBefore?->getBalanceAfterMinorUnits() ?? 0;

        // ── 4. Fetch paginated movements in the range ─────────────────────────
        $page    = $this->ledger->findByAccountIdAndDateRange(
            $ledgerAccountId,
            $from,
            $to,
            $query->page,
            $query->perPage,
        );

        // ── 5. Derive closing balance ─────────────────────────────────────────
        // findLastEntryAtOrBefore($to) uses WHERE occurred_at <= $to — exact inclusive
        // boundary for DATETIME(6) precision.  The previous '+1 second' trick was
        // too coarse: if $to has sub-second precision it could include entries that
        // fall outside the movements range, producing a closing balance inconsistent
        // with the listed movements (a critical fintech data-integrity bug).
        $closingBalance = $openingBalance;
        if ($page->total > 0) {
            $lastInRange = $this->ledger->findLastEntryAtOrBefore($ledgerAccountId, $to);
            if ($lastInRange !== null && $lastInRange->getOccurredAt() >= $from) {
                $closingBalance = $lastInRange->getBalanceAfterMinorUnits();
            }
        }

        // ── 6. Build DTOs ─────────────────────────────────────────────────────
        $lines = array_map(
            fn ($entry) => StatementLineDTO::fromLedgerEntry($entry),
            $page->entries,
        );

        $statement = new AccountStatementDTO(
            accountId:                $account->getId()->toString(),
            ownerName:                $account->getOwnerName(),
            currency:                 $account->getCurrency(),
            from:                     $from->format(\DateTimeInterface::ATOM),
            to:                       $to->format(\DateTimeInterface::ATOM),
            openingBalanceMinorUnits: $openingBalance,
            closingBalanceMinorUnits: $closingBalance,
            movements:                $lines,
            totalMovements:           $page->total,
            page:                     $page->page,
            perPage:                  $page->perPage,
            totalPages:               $page->getTotalPages(),
        );

        $this->logger->info('account.statement_read', [
            'account_id'      => $account->getId()->toString(),
            'from'            => $from->format(\DateTimeInterface::ATOM),
            'to'              => $to->format(\DateTimeInterface::ATOM),
            'total_movements' => $page->total,
            'page'            => $page->page,
            'per_page'        => $page->perPage,
        ]);

        return $statement;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function parseDate(string $value, string $fieldName): \DateTimeImmutable
    {
        if (trim($value) === '') {
            throw new InvalidDateRangeException(
                sprintf('Statement query "%s" date must not be empty.', $fieldName),
            );
        }

        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidDateRangeException(
                sprintf(
                    'Statement query "%s" date "%s" is not a valid ISO 8601 datetime.',
                    $fieldName,
                    $value,
                ),
            );
        }

        // Force UTC — even if the caller provided a timezone offset, normalise to UTC.
        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }
}
