<?php

declare(strict_types=1);

namespace App\Module\Transfer\Infrastructure\Persistence;

use App\Module\Transfer\Application\DTO\PaginatedTransfersDTO;
use App\Module\Transfer\Application\DTO\TransferDTO;
use App\Module\Transfer\Application\Port\TransferReadRepositoryInterface;
use App\Module\Transfer\Domain\Exception\TransferNotFoundException;
use App\Module\Transfer\Domain\Model\Transfer;
use App\Module\Transfer\Domain\Model\TransferStatus;
use App\Module\Transfer\Domain\Repository\TransferRepositoryInterface;
use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed implementation of TransferRepositoryInterface.
 *
 * Uses raw SQL via Doctrine\DBAL\Connection — no ORM, no entity manager.
 * This class is the ONLY place in the Transfer module that knows about the
 * `transfers` table schema.
 *
 * ## Mapping contract
 *
 *   Column                  | PHP type
 *   ----------------------- | ----------------------------------------
 *   id                      | CHAR(36)        — TransferId (UUIDv7)
 *   reference               | VARCHAR(25)     — TransferReference
 *   source_account_id       | CHAR(36)        — AccountId (UUID)
 *   destination_account_id  | CHAR(36)        — AccountId (UUID)
 *   amount_minor_units      | BIGINT UNSIGNED — Money::amountMinorUnits
 *   currency                | CHAR(3)         — Money::currency (ISO 4217)
 *   description             | VARCHAR(500)    — optional narrative
 *   status                  | VARCHAR(20)     — TransferStatus::value
 *   failure_code            | VARCHAR(100)    — machine-readable failure code
 *   failure_reason          | VARCHAR(500)    — human-readable failure reason
 *   completed_at            | DATETIME(6)     — UTC, set on COMPLETED transition
 *   failed_at               | DATETIME(6)     — UTC, set on FAILED transition
 *   created_at              | DATETIME(6)     — UTC, immutable
 *   updated_at              | DATETIME(6)     — UTC, updated on transitions
 *   version                 | INT             — optimistic-lock counter
 */
final class DbalTransferRepository implements TransferRepositoryInterface, TransferReadRepositoryInterface
{
    private const TABLE = 'transfers';

    // DATETIME(6) format — MySQL microsecond-precision datetime
    private const DATETIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so the same aggregate can be
     * saved on creation and on every subsequent state transition without
     * the caller needing to distinguish INSERT from UPDATE.
     */
    public function save(Transfer $transfer): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO transfers
                (id, reference, source_account_id, destination_account_id,
                 amount_minor_units, currency, description, idempotency_key, status,
                 failure_code, failure_reason, completed_at, failed_at, reversed_at,
                 created_at, updated_at, version)
            VALUES
                (:id, :reference, :source, :dest, :amount, :currency,
                 :description, :idempotency_key, :status, :failure_code, :failure_reason,
                 :completed_at, :failed_at, :reversed_at,
                 :created_at, :updated_at, :version)
            ON DUPLICATE KEY UPDATE
                status         = VALUES(status),
                failure_code   = VALUES(failure_code),
                failure_reason = VALUES(failure_reason),
                completed_at   = VALUES(completed_at),
                failed_at      = VALUES(failed_at),
                reversed_at    = VALUES(reversed_at),
                updated_at     = VALUES(updated_at),
                version        = VALUES(version)
            SQL,
            [
                'id'             => $transfer->getId()->toString(),
                'reference'      => $transfer->getReference()->toString(),
                'source'         => $transfer->getSourceAccountId()->toString(),
                'dest'           => $transfer->getDestinationAccountId()->toString(),
                'amount'         => $transfer->getAmount()->getAmountMinorUnits(),
                'currency'       => $transfer->getAmount()->getCurrency(),
                'description'      => $transfer->getDescription(),
                'idempotency_key'  => $transfer->getIdempotencyKey(),
                'status'           => $transfer->getStatus()->value,
                'failure_code'   => $transfer->getFailureCode(),
                'failure_reason' => $transfer->getFailureReason(),
                'completed_at'   => $transfer->getCompletedAt()?->format(self::DATETIME_FORMAT),
                'failed_at'      => $transfer->getFailedAt()?->format(self::DATETIME_FORMAT),
                'reversed_at'    => $transfer->getReversedAt()?->format(self::DATETIME_FORMAT),
                'created_at'     => $transfer->getCreatedAt()->format(self::DATETIME_FORMAT),
                'updated_at'     => $transfer->getUpdatedAt()->format(self::DATETIME_FORMAT),
                'version'        => $transfer->getVersion(),
            ]
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findById(TransferId $id): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, reference, source_account_id, destination_account_id,'
            . ' amount_minor_units, currency, description, idempotency_key, status,'
            . ' failure_code, failure_reason, completed_at, failed_at, reversed_at,'
            . ' created_at, updated_at, version'
            . ' FROM ' . self::TABLE . ' WHERE id = ?',
            [$id->toString()]
        );

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * {@inheritDoc}
     */
    public function getById(TransferId $id): Transfer
    {
        $transfer = $this->findById($id);

        if ($transfer === null) {
            throw new TransferNotFoundException(
                sprintf('Transfer "%s" not found.', $id->toString())
            );
        }

        return $transfer;
    }

    /**
     * {@inheritDoc}
     *
     * Acquires a SELECT … FOR UPDATE row lock to prevent concurrent reversals
     * from both reading COMPLETED and both calling reverse().
     */
    public function getByIdForUpdate(TransferId $id): Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, reference, source_account_id, destination_account_id,'
            . ' amount_minor_units, currency, description, idempotency_key, status,'
            . ' failure_code, failure_reason, completed_at, failed_at, reversed_at,'
            . ' created_at, updated_at, version'
            . ' FROM ' . self::TABLE . ' WHERE id = ? FOR UPDATE',
            [$id->toString()]
        );

        if ($row === false) {
            throw new TransferNotFoundException(
                sprintf('Transfer "%s" not found.', $id->toString())
            );
        }

        return $this->hydrate($row);
    }

    /**
     * {@inheritDoc}
     */
    public function findByFilters(
        ?string $status    = null,
        ?string $accountId = null,
        int     $page      = 1,
        int     $perPage   = 25,
    ): PaginatedTransfersDTO {
        $where  = [];
        $params = [];

        if ($status !== null) {
            $where[]  = 'status = :status';
            $params['status'] = $status;
        }

        if ($accountId !== null) {
            $where[]             = '(source_account_id = :account_id OR destination_account_id = :account_id)';
            $params['account_id'] = $accountId;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';


        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s %s', self::TABLE, $whereClause),
            $params,
        );

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        $offset     = ($page - 1) * $perPage;


        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id, reference, source_account_id, destination_account_id,'
                . ' amount_minor_units, currency, description, idempotency_key, status,'
                . ' failure_code, failure_reason, completed_at, failed_at, reversed_at,'
                . ' created_at, updated_at, version'
                . ' FROM %s %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
                self::TABLE,
                $whereClause,
                $perPage,
                $offset,
            ),
            $params,
        );

        $items = array_map(
            fn (array $row) => TransferDTO::fromTransfer($this->hydrate($row)),
            $rows,
        );

        return new PaginatedTransfersDTO(
            items:      $items,
            total:      $total,
            page:       $page,
            perPage:    $perPage,
            totalPages: $totalPages,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private: row → aggregate
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Transfer
    {
        return Transfer::reconstitute(
            id:                   TransferId::fromString((string) $row['id']),
            reference:            TransferReference::fromString((string) $row['reference']),
            sourceAccountId:      AccountId::fromString((string) $row['source_account_id']),
            destinationAccountId: AccountId::fromString((string) $row['destination_account_id']),
            amount:               new Money((int) $row['amount_minor_units'], (string) $row['currency']),
            status:               TransferStatus::from((string) $row['status']),
            createdAt:            $this->parseDateTime((string) $row['created_at']),
            updatedAt:            $this->parseDateTime((string) $row['updated_at']),
            description:          isset($row['description']) && $row['description'] !== null
                                      ? (string) $row['description'] : null,
            failureCode:          isset($row['failure_code']) && $row['failure_code'] !== null
                                      ? (string) $row['failure_code'] : null,
            failureReason:        isset($row['failure_reason']) && $row['failure_reason'] !== null
                                      ? (string) $row['failure_reason'] : null,
            completedAt:          isset($row['completed_at']) && $row['completed_at'] !== null
                                      ? $this->parseDateTime((string) $row['completed_at']) : null,
            failedAt:             isset($row['failed_at']) && $row['failed_at'] !== null
                                      ? $this->parseDateTime((string) $row['failed_at']) : null,
            reversedAt:           isset($row['reversed_at']) && $row['reversed_at'] !== null
                                      ? $this->parseDateTime((string) $row['reversed_at']) : null,
            version:              (int) $row['version'],
            idempotencyKey:       isset($row['idempotency_key']) && $row['idempotency_key'] !== null
                                      ? (string) $row['idempotency_key'] : null,
        );
    }

    /**
     * Find the transfer committed for a given idempotency key, or null.
     *
     * Called from InitiateTransferHandler inside a transaction to close the
     * crash-after-commit window.  Uses a plain SELECT — no FOR UPDATE needed
     * because the GET_LOCK advisory lock in IdempotencySubscriber serialises
     * concurrent first-requests before this point.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, reference, source_account_id, destination_account_id,'
            . ' amount_minor_units, currency, description, idempotency_key, status,'
            . ' failure_code, failure_reason, completed_at, failed_at, reversed_at,'
            . ' created_at, updated_at, version'
            . ' FROM ' . self::TABLE . ' WHERE idempotency_key = ?',
            [$idempotencyKey]
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * The reference column has a UNIQUE index — O(log N) seek.
     */
    public function findByReference(\App\Module\Transfer\Domain\ValueObject\TransferReference $reference): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, reference, source_account_id, destination_account_id,'
            . ' amount_minor_units, currency, description, idempotency_key, status,'
            . ' failure_code, failure_reason, completed_at, failed_at, reversed_at,'
            . ' created_at, updated_at, version'
            . ' FROM ' . self::TABLE . ' WHERE reference = ?',
            [$reference->toString()]
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    private function parseDateTime(string $value): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value, new \DateTimeZone('UTC'));

        if ($dt !== false) {
            return $dt;
        }

        // Fallback: MySQL may omit microseconds when they are exactly zero.
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to parse datetime value "%s" from the persistence layer. '
                    . 'The transfers table may contain corrupt timestamp data.',
                    $value,
                ),
                0,
                $e,
            );
        }
    }
}
