<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command\CloseAccount;

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
 * Handles the CloseAccountCommand use-case.
 *
 * Loads the Account, transitions it to CLOSED, and persists within a transaction.
 * CLOSED is terminal — the account can never be reopened after this command.
 *
 * ## TOCTOU safety
 *   getByIdForUpdate() acquires a pessimistic row lock so two concurrent close
 *   requests cannot both succeed (the second will see CLOSED and throw
 *   InvalidAccountStateException).
 *
 * ## Balance invariant
 *   account->close() throws NonZeroBalanceOnCloseException if the balance is
 *   not zero.  The handler propagates this exception to the caller → 409.
 *
 * ## Outbox pattern
 *   AccountClosed is written to outbox_events atomically inside the transaction
 *   (same guarantees as Transfer events).  After commit, events are also
 *   dispatched synchronously for in-process consumers.
 */
final class CloseAccountHandler
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
     * @throws \App\Module\Account\Domain\Exception\InvalidAccountStateException   when already CLOSED
     * @throws \App\Module\Account\Domain\Exception\NonZeroBalanceOnCloseException when balance > 0
     */
    public function __invoke(CloseAccountCommand $command): AccountDTO
    {
        $accountId = AccountId::fromString($command->accountId);

        /** @var Account $account */
        $account = null;

        $this->transactionManager->transactional(function () use ($accountId, &$account): void {
            $account = $this->accounts->getByIdForUpdate($accountId);
            $account->close();
            $this->accounts->save($account);

            // Write AccountClosed to outbox atomically — event delivery is
            // guaranteed even if the process crashes before in-process dispatch.
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

        $this->logger->info('account.closed', [
            'account_id'           => $account->getId()->toString(),
            'final_balance_minor'  => $account->getBalance()->getAmountMinorUnits(),
            'currency'             => $account->getBalance()->getCurrency(),
        ]);

        return AccountDTO::fromAccount($account);
    }
}
