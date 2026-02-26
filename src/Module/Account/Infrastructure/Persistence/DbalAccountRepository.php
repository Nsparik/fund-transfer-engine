<?php

declare(strict_types=1);

namespace App\Module\Account\Infrastructure\Persistence;

use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\Model\AccountStatus;
use App\Module\Account\Domain\Repository\AccountRepositoryInterface;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Domain\ValueObject\Balance;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed implementation of AccountRepositoryInterface.
 *
 * Uses raw SQL via Doctrine\DBAL\Connection — no ORM, no entity manager.
 * This class is the ONLY place in the Account module that knows about the
 * `accounts` table schema.
 *
 * ## Mapping contract
 *
 *   Column                  | PHP type
 *   ----------------------- | ----------------------------------------
 *   id                      | CHAR(36)        — AccountId (UUID v4)
 *   owner_name              | VARCHAR(255)    — human-readable holder name
 *   currency                | CHAR(3)         — ISO 4217 code
 *   balance_minor_units     | BIGINT UNSIGNED — Balance::amountMinorUnits
 *   status                  | VARCHAR(20)     — AccountStatus::value
 *   created_at              | DATETIME(6)     — UTC, immutable
 *   updated_at              | DATETIME(6)     — UTC, updated on mutations
 *   version                 | INT             — optimistic-lock counter
 *
 * ## Concurrency
 *   save() is an upsert (INSERT … ON DUPLICATE KEY UPDATE).
 *   getByIdForUpdate() acquires a pessimistic SELECT … FOR UPDATE row lock;
 *   the transfer handler uses this to safely debit/credit accounts within a
 *   single database transaction.
 */
final class DbalAccountRepository implements AccountRepositoryInterface
{
    private const TABLE           = 'accounts';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so both creation and subsequent
     * balance/status mutations use the same code path.
     */
    public function save(Account $account): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO accounts
                (id, owner_name, currency, balance_minor_units, status,
                 created_at, updated_at, closed_at, version)
            VALUES
                (:id, :owner_name, :currency, :balance, :status,
                 :created_at, :updated_at, :closed_at, :version)
            ON DUPLICATE KEY UPDATE
                balance_minor_units = VALUES(balance_minor_units),
                status              = VALUES(status),
                updated_at          = VALUES(updated_at),
                closed_at           = VALUES(closed_at),
                version             = VALUES(version)
            SQL,
            [
                'id'         => $account->getId()->toString(),
                'owner_name' => $account->getOwnerName(),
                'currency'   => $account->getCurrency(),
                'balance'    => $account->getBalance()->getAmountMinorUnits(),
                'status'     => $account->getStatus()->value,
                'created_at' => $account->getCreatedAt()->format(self::DATETIME_FORMAT),
                'updated_at' => $account->getUpdatedAt()->format(self::DATETIME_FORMAT),
                'closed_at'  => $account->getClosedAt()?->format(self::DATETIME_FORMAT),
                'version'    => $account->getVersion(),
            ]
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findById(AccountId $id): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, owner_name, currency, balance_minor_units, status,'
            . ' created_at, updated_at, closed_at, version'
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
    public function getById(AccountId $id): Account
    {
        $account = $this->findById($id);

        if ($account === null) {
            throw new AccountNotFoundException(
                sprintf('Account "%s" not found.', $id->toString())
            );
        }

        return $account;
    }

    /**
     * {@inheritDoc}
     */
    public function getByIdForUpdate(AccountId $id): Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, owner_name, currency, balance_minor_units, status,'
            . ' created_at, updated_at, closed_at, version'
            . ' FROM ' . self::TABLE . ' WHERE id = ? FOR UPDATE',
            [$id->toString()]
        );

        if ($row === false) {
            throw new AccountNotFoundException(
                sprintf('Account "%s" not found.', $id->toString())
            );
        }

        return $this->hydrate($row);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private: row → aggregate
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Account
    {
        return Account::reconstitute(
            id:        AccountId::fromString((string) $row['id']),
            ownerName: (string) $row['owner_name'],
            currency:  (string) $row['currency'],
            balance:   new Balance((int) $row['balance_minor_units'], (string) $row['currency']),
            status:    AccountStatus::from((string) $row['status']),
            createdAt: $this->parseDateTime((string) $row['created_at']),
            updatedAt: $this->parseDateTime((string) $row['updated_at']),
            version:   (int) $row['version'],
            closedAt:  isset($row['closed_at']) ? $this->parseDateTime((string) $row['closed_at']) : null,
        );
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
                    'Failed to parse datetime value "%s" from the accounts table.',
                    $value,
                ),
                0,
                $e,
            );
        }
    }
}
