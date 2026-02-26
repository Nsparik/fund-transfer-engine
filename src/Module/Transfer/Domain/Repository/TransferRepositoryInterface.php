<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Repository;

use App\Module\Transfer\Domain\Exception\TransferNotFoundException;
use App\Module\Transfer\Domain\Model\Transfer;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;

/**
 * Port (outgoing, secondary port) for Transfer persistence.
 *
 * This interface lives in the Domain layer.  Infrastructure adapters
 * (e.g. DbalTransferRepository) implement it from the outside, keeping the
 * domain free of Doctrine / DBAL / MySQL concerns.
 */
interface TransferRepositoryInterface
{
    /**
     * Persist a new or updated Transfer aggregate.
     *
     * Must be idempotent for the same ID within one request cycle.
     */
    public function save(Transfer $transfer): void;

    /**
     * Return the Transfer with the given ID, or null if it does not exist.
     */
    public function findById(TransferId $id): ?Transfer;

    /**
     * Return the Transfer with the given ID.
     *
     * @throws TransferNotFoundException when no Transfer exists for the ID
     */
    public function getById(TransferId $id): Transfer;

    /**
     * Return the Transfer with the given ID, acquiring a pessimistic row-level lock.
     *
     * Uses SELECT … FOR UPDATE to block concurrent writers for the duration of the
     * enclosing transaction.  MUST be called inside an active database transaction.
     *
     * Use this method in ReverseTransferHandler to prevent two concurrent reversal
     * requests from both reading COMPLETED status and both calling reverse().
     *
     * @throws TransferNotFoundException when no Transfer exists for the ID
     */
    public function getByIdForUpdate(TransferId $id): Transfer;

    /**
     * Return the most-recently committed Transfer for the given idempotency key,
     * or null when no transfer has ever been committed with that key.
     *
     * ## Purpose
     *   Closes the crash-after-commit window: if the process dies after the
     *   transfer transaction commits but before the HTTP idempotency record is
     *   saved, the next retry calls this method inside a new transaction and
     *   returns the existing DTO without re-executing the double-entry.
     *
     * ## Locking
     *   Uses a plain SELECT (no FOR UPDATE).  The crash-recovery path is
     *   sequential (only one process retries after another's crash), so
     *   additional row locking adds contention without benefit.  The advisory
     *   GET_LOCK in IdempotencySubscriber serialises concurrent first-requests
     *   before this method is reached.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer;

    /**
     * Return the Transfer with the given human-readable reference, or null.
     *
     * The reference column has a UNIQUE index — O(log N) seek.
     * Intended for customer-support / dispute-resolution tooling where the
     * caller knows the TXN-YYYYMMDD-XXXXXXXXXXXX reference but not the UUID.
     */
    public function findByReference(TransferReference $reference): ?Transfer;
}
