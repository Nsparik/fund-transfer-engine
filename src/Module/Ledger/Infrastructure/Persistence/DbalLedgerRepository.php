<?php

declare(strict_types=1);

namespace App\Module\Ledger\Infrastructure\Persistence;

use App\Module\Ledger\Domain\Model\LedgerEntry;
use App\Module\Ledger\Domain\Model\LedgerPage;
use App\Module\Ledger\Domain\Repository\LedgerRepositoryInterface;
use App\Module\Ledger\Domain\ValueObject\AccountId;
use App\Module\Ledger\Domain\ValueObject\EntryType;
use App\Module\Ledger\Domain\ValueObject\LedgerEntryId;
use App\Shared\Application\Port\LedgerEntryRecorderPort;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed implementation of LedgerRepositoryInterface.
 *
 * Uses raw SQL via Doctrine\DBAL\Connection — no ORM, no entity manager.
 * This class is the ONLY place in the Ledger module that knows about the
 * `ledger_entries` table schema.
 *
 * ## Idempotency
 *   save() uses INSERT IGNORE — the UNIQUE constraint on
 *   (account_id, transfer_id, entry_type) silently discards a duplicate write.
 *
 * ## No FK constraint on transfer_id (by design)
 *   ledger_entries.transfer_id is intentionally NOT foreign-key constrained to
 *   transfers.id.  Bootstrap entries (written by CreateAccountHandler and the
 *   backfill migration) use a synthetic SYSTEM_BOOTSTRAP_TRANSFER_ID
 *   (00000000-0000-7000-8000-000000000001) that has no row in transfers.
 *   Adding the FK would break bootstrap writes.
 *
 *   INVARIANT: the ONLY code paths that write to ledger_entries are:
 *     1. DbalLedgerRepository::recordTransferEntries()  — transfer/reversal entries
 *     2. DbalLedgerRepository::recordBootstrapCreditEntry() — opening-balance entries
 *
 *   Any NEW code path that writes to ledger_entries directly (bypassing these
 *   two methods) risks orphaned entries with a non-existent transfer_id.
 *   ReconcileBalancesService compares account balances against ledger snapshots
 *   by account_id — it would NOT surface an orphaned entry with a wrong
 *   transfer_id but a correct account_id and balance_after value.
 *
 *   Mitigation: never write ledger_entries outside these two methods.
 *   If a third write path is ever needed, add it here and update this comment.
 *   This means the handler can be retried safely with no ledger corruption.
 *
 * ## Mapping contract
 *
 *   Column                    | PHP type
 *   ------------------------- | ------------------------------------------
 *   id                        | CHAR(36) — LedgerEntryId (UUID v7)
 *   account_id                | CHAR(36) — AccountId
 *   counterparty_account_id   | CHAR(36) — AccountId
 *   transfer_id               | CHAR(36) — string reference to transfers.id
 *   entry_type                | VARCHAR(10) — EntryType::value
 *   transfer_type             | VARCHAR(20) — 'transfer' | 'reversal' | 'bootstrap'
 *   amount_minor_units        | BIGINT UNSIGNED — int
 *   currency                  | CHAR(3) — ISO 4217
 *   balance_after_minor_units | BIGINT UNSIGNED — int
 *   occurred_at               | DATETIME(6) — UTC
 *   created_at                | DATETIME(6) — UTC
 */
final class DbalLedgerRepository implements LedgerRepositoryInterface, LedgerEntryRecorderPort
{
    private const TABLE           = 'ledger_entries';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * INSERT IGNORE ensures idempotency: if a row with the same
     * (account_id, transfer_id, entry_type) already exists, the statement
     * succeeds silently without modifying any data.
     */
    public function save(LedgerEntry $entry): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT IGNORE INTO ledger_entries
                (id, account_id, counterparty_account_id, transfer_id,
                 entry_type, transfer_type, amount_minor_units, currency,
                 balance_after_minor_units, occurred_at, created_at)
            VALUES
                (:id, :account_id, :counterparty_account_id, :transfer_id,
                 :entry_type, :transfer_type, :amount_minor_units, :currency,
                 :balance_after_minor_units, :occurred_at, :created_at)
            SQL,
            [
                'id'                        => $entry->getId()->toString(),
                'account_id'                => $entry->getAccountId()->toString(),
                'counterparty_account_id'   => $entry->getCounterpartyAccountId()->toString(),
                'transfer_id'               => $entry->getTransferId(),
                'entry_type'                => $entry->getEntryType()->value,
                'transfer_type'             => $entry->getTransferType(),
                'amount_minor_units'        => $entry->getAmountMinorUnits(),
                'currency'                  => $entry->getCurrency(),
                'balance_after_minor_units' => $entry->getBalanceAfterMinorUnits(),
                'occurred_at'               => $entry->getOccurredAt()->format(self::DATETIME_FORMAT),
                'created_at'                => $entry->getCreatedAt()->format(self::DATETIME_FORMAT),
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findByAccountIdAndDateRange(
        AccountId          $accountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int                $page,
        int                $perPage,
    ): LedgerPage {
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
            FROM ledger_entries
            WHERE account_id  = :account_id
              AND occurred_at >= :from
              AND occurred_at <= :to
            SQL,
            [
                'account_id' => $accountId->toString(),
                'from'       => $from->format(self::DATETIME_FORMAT),
                'to'         => $to->format(self::DATETIME_FORMAT),
            ],
        );

        if ($total === 0) {
            return new LedgerPage([], 0, $page, $perPage);
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT id, account_id, counterparty_account_id, transfer_id,
                   entry_type, transfer_type, amount_minor_units, currency,
                   balance_after_minor_units, occurred_at, created_at
            FROM ledger_entries
            WHERE account_id  = :account_id
              AND occurred_at >= :from
              AND occurred_at <= :to
            ORDER BY occurred_at DESC, id DESC
            LIMIT  :limit
            OFFSET :offset
            SQL,
            [
                'account_id' => $accountId->toString(),
                'from'       => $from->format(self::DATETIME_FORMAT),
                'to'         => $to->format(self::DATETIME_FORMAT),
                'limit'      => $perPage,
                'offset'     => $offset,
            ],
            [
                'limit'  => \Doctrine\DBAL\ParameterType::INTEGER,
                'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        );

        $entries = array_map(fn (array $row) => $this->hydrate($row), $rows);

        return new LedgerPage($entries, $total, $page, $perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function findLastEntryBefore(
        AccountId          $accountId,
        \DateTimeImmutable $before,
    ): ?LedgerEntry {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT id, account_id, counterparty_account_id, transfer_id,
                   entry_type, transfer_type, amount_minor_units, currency,
                   balance_after_minor_units, occurred_at, created_at
            FROM ledger_entries
            WHERE account_id = :account_id
              AND occurred_at < :before
            ORDER BY occurred_at DESC, id DESC
            LIMIT 1
            SQL,
            [
                'account_id' => $accountId->toString(),
                'before'     => $before->format(self::DATETIME_FORMAT),
            ],
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * {@inheritDoc}
     *
     * WHERE occurred_at <= :at (inclusive) — used for closing balance at range end.
     */
    public function findLastEntryAtOrBefore(
        AccountId          $accountId,
        \DateTimeImmutable $at,
    ): ?LedgerEntry {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT id, account_id, counterparty_account_id, transfer_id,
                   entry_type, transfer_type, amount_minor_units, currency,
                   balance_after_minor_units, occurred_at, created_at
            FROM ledger_entries
            WHERE account_id = :account_id
              AND occurred_at <= :at
            ORDER BY occurred_at DESC, id DESC
            LIMIT 1
            SQL,
            [
                'account_id' => $accountId->toString(),
                'at'         => $at->format(self::DATETIME_FORMAT),
            ],
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function findLastEntryForAccount(AccountId $accountId): ?LedgerEntry
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT id, account_id, counterparty_account_id, transfer_id,
                   entry_type, transfer_type, amount_minor_units, currency,
                   balance_after_minor_units, occurred_at, created_at
            FROM ledger_entries
            WHERE account_id = :account_id
            ORDER BY occurred_at DESC, id DESC
            LIMIT 1
            SQL,
            [
                'account_id' => $accountId->toString(),
            ],
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LedgerEntryRecorderPort implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * Writes a single CREDIT entry for the real account.  Only the CREDIT side
     * is written because the synthetic counterparty UUID used for bootstrap
     * entries does not exist in the accounts table, so a DEBIT with
     * account_id = system-UUID would violate the FK constraint added in
     * Version20260226000001AddForeignKeyConstraints.
     *
     * The counterparty_account_id column has no FK — the system UUID is safe
     * to store there.
     */
    public function recordBootstrapCreditEntry(
        string             $accountId,
        string             $systemCounterpartyId,
        string             $transferId,
        int                $amountMinorUnits,
        string             $currency,
        \DateTimeImmutable $occurredAt,
    ): void {
        $creditEntry = LedgerEntry::recordCredit(
            accountId:              AccountId::fromString($accountId),
            counterpartyAccountId:  AccountId::fromString($systemCounterpartyId),
            transferId:             $transferId,
            transferType:           'bootstrap',
            amountMinorUnits:       $amountMinorUnits,
            currency:               $currency,
            balanceAfterMinorUnits: $amountMinorUnits, // opening credit = full opening balance
            occurredAt:             $occurredAt,
        );

        $this->save($creditEntry);
    }

    /**
     * {@inheritDoc}
     *
     * Both rows share the same $transferId and $occurredAt so they can be
     * correlated in a single index scan on idx_ledger_transfer.
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
    ): void {
        $sourceAccId = AccountId::fromString($sourceAccountId);
        $destAccId   = AccountId::fromString($destinationAccountId);

        $debitEntry = LedgerEntry::recordDebit(
            accountId:              $sourceAccId,
            counterpartyAccountId:  $destAccId,
            transferId:             $transferId,
            transferType:           $transferType,
            amountMinorUnits:       $amountMinorUnits,
            currency:               $currency,
            balanceAfterMinorUnits: $sourceBalanceAfterMinorUnits,
            occurredAt:             $occurredAt,
        );

        $creditEntry = LedgerEntry::recordCredit(
            accountId:              $destAccId,
            counterpartyAccountId:  $sourceAccId,
            transferId:             $transferId,
            transferType:           $transferType,
            amountMinorUnits:       $amountMinorUnits,
            currency:               $currency,
            balanceAfterMinorUnits: $destinationBalanceAfterMinorUnits,
            occurredAt:             $occurredAt,
        );

        $this->save($debitEntry);
        $this->save($creditEntry);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: row → aggregate
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LedgerEntry
    {
        return LedgerEntry::reconstitute(
            id:                     LedgerEntryId::fromString((string) $row['id']),
            accountId:              AccountId::fromString((string) $row['account_id']),
            counterpartyAccountId:  AccountId::fromString((string) $row['counterparty_account_id']),
            transferId:             (string) $row['transfer_id'],
            entryType:              EntryType::from((string) $row['entry_type']),
            transferType:           (string) $row['transfer_type'],
            amountMinorUnits:       (int) $row['amount_minor_units'],
            currency:               (string) $row['currency'],
            balanceAfterMinorUnits: (int) $row['balance_after_minor_units'],
            occurredAt:             $this->parseDateTime((string) $row['occurred_at']),
            createdAt:              $this->parseDateTime((string) $row['created_at']),
        );
    }

    private function parseDateTime(string $value): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat(
            self::DATETIME_FORMAT,
            $value,
            new \DateTimeZone('UTC'),
        );

        if ($dt !== false) {
            return $dt;
        }

        // Fallback: MySQL may omit microseconds when they are exactly zero.
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                sprintf('Failed to parse datetime value "%s" from the ledger_entries table.', $value),
                0,
                $e,
            );
        }
    }
}
