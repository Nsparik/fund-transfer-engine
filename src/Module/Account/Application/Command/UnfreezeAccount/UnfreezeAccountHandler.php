<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command\UnfreezeAccount;

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
 * Handles the UnfreezeAccountCommand use-case.
 *
 * Loads the Account, transitions it from FROZEN back to ACTIVE, and
 * persists within a transaction.
 * AccountUnfrozen is written to the outbox atomically (at-least-once delivery guarantee).
 *
 * ## TOCTOU safety
 *   getById(), unfreeze(), and save() all execute inside one transactional()
 *   closure — preventing a race condition between the read and the write.
 */
final class UnfreezeAccountHandler
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
     * @throws \App\Module\Account\Domain\Exception\InvalidAccountStateException when not FROZEN
     */
    public function __invoke(UnfreezeAccountCommand $command): AccountDTO
    {
        $accountId = AccountId::fromString($command->accountId);

        /** @var Account $account */
        $account = null;

        $this->transactionManager->transactional(function () use ($accountId, &$account): void {
            // Use pessimistic SELECT … FOR UPDATE to prevent a concurrent unfreeze
            // and freeze from both reading the stale status and dispatching
            // duplicate events at the same version number.
            $account = $this->accounts->getByIdForUpdate($accountId);
            $account->unfreeze();
            $this->accounts->save($account);

            // Write AccountUnfrozen to outbox atomically — at-least-once delivery
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

        $this->logger->info('account.unfrozen', [
            'account_id' => $account->getId()->toString(),
        ]);

        return AccountDTO::fromAccount($account);
    }
}
