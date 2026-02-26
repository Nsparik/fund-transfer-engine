<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Repository;

use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\ValueObject\AccountId;

/**
 * Port (outgoing, secondary) for Account persistence.
 *
 * Lives in the Domain layer.  Infrastructure adapters implement it from
 * the outside, keeping the domain free of Doctrine/DBAL/MySQL concerns.
 */
interface AccountRepositoryInterface
{
    /**
     * Persist a new or updated Account aggregate.
     *
     * Must be idempotent for the same ID within one request cycle.
     */
    public function save(Account $account): void;

    /**
     * Return the Account with the given ID, or null if it does not exist.
     */
    public function findById(AccountId $id): ?Account;

    /**
     * Return the Account with the given ID.
     *
     * @throws AccountNotFoundException when no Account exists for the ID
     */
    public function getById(AccountId $id): Account;

    /**
     * Return the Account with the given ID, acquiring a pessimistic row-level lock.
     *
     * Uses SELECT … FOR UPDATE to block concurrent writers for the duration of the
     * enclosing transaction.  MUST be called inside an active database transaction.
     *
     * Use this method whenever you intend to mutate the account (debit/credit) and
     * must prevent concurrent transfers from reading a stale balance.
     *
     * @throws AccountNotFoundException when no Account exists for the ID
     */
    public function getByIdForUpdate(AccountId $id): Account;
}
