<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command\FreezeAccount;

use App\Module\Account\Application\DTO\AccountDTO;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\Repository\AccountRepositoryInterface;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Shared\Application\Port\TransactionManagerInterface;
use App\Shared\Domain\Outbox\OutboxEventSerializerInterface;
use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Handles the FreezeAccountCommand use-case.
 *
 * Loads the Account, transitions it to FROZEN, and persists within a transaction.
 * AccountFrozen is written to the outbox atomically (at-least-once delivery guarantee).
 *
 * ## TOCTOU safety
 *   getById(), freeze(), and save() all execute inside a single transactional()
 *   closure.  This prevents a race condition where the account status changes
 *   between the read and the write.  Debit/credit paths in the transfer
 *   handler use getByIdForUpdate() for pessimistic row locking.
 */
final class FreezeAccountHandler
{
    public function __construct(
        private readonly AccountRepositoryInterface     $accounts,
        private readonly TransactionManagerInterface    $transactionManager,
        private readonly EventDispatcherInterface       $dispatcher,
        private readonly LoggerInterface                $logger,
        private readonly OutboxRepositoryInterface      $outbox,
        private readonly OutboxEventSerializerInterface $serializer,
    ) {}

    /**
     * @throws \App\Module\Account\Domain\Exception\AccountNotFoundException
     * @throws \App\Module\Account\Domain\Exception\InvalidAccountStateException when already FROZEN or CLOSED
     */
    public function __invoke(FreezeAccountCommand $command): AccountDTO
    {
        $accountId = AccountId::fromString($command->accountId);

        /** @var Account $account */
        $account = null;

        $this->transactionManager->transactional(function () use ($accountId, &$account): void {
            // Use pessimistic SELECT … FOR UPDATE to prevent two concurrent freeze
            // requests from both reading status=ACTIVE, both succeeding, and
            // dispatching two AccountFrozen events with the same final version.
            $account = $this->accounts->getByIdForUpdate($accountId);
            $account->freeze();
            $this->accounts->save($account);

            // Write AccountFrozen to outbox atomically — at-least-once delivery
            // guarantee even if the process crashes before in-process dispatch.
            foreach ($account->peekEvents() as $event) {
                $this->outbox->save(
                    $this->serializer->serialize(
                        $event,
                        'Account',
                        $account->getId()->toString(),
                    )
                );
            }
        });

        // Post-commit: release events and dispatch synchronously for in-process consumers.
        foreach ($account->releaseEvents() as $event) {
            $this->dispatcher->dispatch($event);
        }

        $this->logger->info('account.frozen', [
            'account_id' => $account->getId()->toString(),
        ]);

        return AccountDTO::fromAccount($account);
    }
}
