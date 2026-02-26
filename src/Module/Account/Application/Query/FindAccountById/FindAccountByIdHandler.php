<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Query\FindAccountById;

use App\Module\Account\Application\DTO\AccountDTO;
use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Repository\AccountRepositoryInterface;
use App\Module\Account\Domain\ValueObject\AccountId;
use Psr\Log\LoggerInterface;

/**
 * Returns the AccountDTO for a given account ID.
 *
 * Malformed UUIDs are treated as not-found to avoid leaking internal details.
 * Emits an audit log entry on every successful balance read so that sensitive
 * lookups are traceable without logging PII.
 *
 * @throws AccountNotFoundException when the ID does not exist or is malformed
 */
final class FindAccountByIdHandler
{
    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
        private readonly LoggerInterface            $logger,
    ) {}

    public function __invoke(FindAccountByIdQuery $query): AccountDTO
    {
        try {
            $id = AccountId::fromString($query->accountId);
        } catch (\InvalidArgumentException) {
            throw new AccountNotFoundException(
                sprintf('Account "%s" not found.', $query->accountId)
            );
        }

        $dto = AccountDTO::fromAccount(
            $this->accounts->getById($id)
        );

        // Audit every balance read — supports anomaly detection without logging PII.
        $this->logger->info('account.balance_read', [
            'account_id' => $dto->id,
            'status'     => $dto->status,
            'currency'   => $dto->currency,
        ]);

        return $dto;
    }
}
