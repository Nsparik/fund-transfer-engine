<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command\CreateAccount;

use App\Module\Account\Application\DTO\AccountDTO;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\Repository\AccountRepositoryInterface;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Shared\Application\Port\LedgerEntryRecorderPort;
use App\Shared\Application\Port\TransactionManagerInterface;
use App\Shared\Domain\Outbox\OutboxEventSerializerInterface;
use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Handles the CreateAccountCommand use-case.
 *
 * Opens a new Account aggregate, persists it within a transaction, writes
 * AccountCreated to the outbox atomically (at-least-once delivery guarantee),
 * and dispatches collected domain events after commit for in-process consumers.
 *
 * When initialBalanceMinorUnits > 0, a bootstrap CREDIT ledger entry is written
 * atomically inside the same transaction so that ReconcileBalancesService
 * always reports a zero-mismatch for newly created accounts.
 */
final class CreateAccountHandler
{
    /**
     * Fixed system UUID used as the synthetic counterparty account ID on
     * bootstrap ledger entries.  Must match the value used in the backfill
     * migration (Version20260225000001BackfillInitialBalanceLedgerEntries) and
     * in the LedgerEntry assertAccountsDiffer guard (they must differ).
     */
    private const SYSTEM_COUNTERPARTY_ACCOUNT_ID = '00000000-0000-7000-8000-000000000000';

    /**
     * Fixed system UUID used as the synthetic transfer ID on bootstrap ledger
     * entries.  Per-account uniqueness is enforced by the UNIQUE constraint
     * uidx_ledger_account_transfer_type(account_id, transfer_id, entry_type).
     * Two accounts opening on the same day get the same transfer_id but
     * different account_ids, so the constraint is not violated.
     */
    private const SYSTEM_BOOTSTRAP_TRANSFER_ID = '00000000-0000-7000-8000-000000000001';

    public function __construct(
        private readonly AccountRepositoryInterface     $accounts,
        private readonly TransactionManagerInterface    $transactionManager,
        private readonly EventDispatcherInterface       $dispatcher,
        private readonly LoggerInterface                $logger,
        private readonly OutboxRepositoryInterface      $outbox,
        private readonly OutboxEventSerializerInterface $serializer,
        private readonly LedgerEntryRecorderPort        $ledgerRecorder,
    ) {}

    /**
     * @throws \InvalidArgumentException on invalid UUID, blank owner name, or invalid currency
     */
    public function __invoke(CreateAccountCommand $command): AccountDTO
    {
        $account = Account::open(
            id:                       AccountId::fromString($command->accountId),
            ownerName:                $command->ownerName,
            currency:                 $command->currency,
            initialBalanceMinorUnits: $command->initialBalanceMinorUnits,
        );

        $this->transactionManager->transactional(function () use ($account): void {
            $this->accounts->save($account);

            // Write a bootstrap CREDIT ledger entry when the account opens with
            // a non-zero balance.  This ensures ReconcileBalancesService always
            // sees a zero mismatch for freshly created accounts, and the ledger
            // can reconstruct full financial history from account creation.
            //
            // recordBootstrapCreditEntry() writes ONLY the CREDIT side (the real
            // account is credited).  We do NOT use recordTransferEntries() here
            // because that method also writes a DEBIT row with
            // account_id = SYSTEM_COUNTERPARTY_ACCOUNT_ID — a synthetic UUID that
            // has no row in the accounts table.  Writing that DEBIT would violate
            // the FK constraint fk_ledger_entries_account added in
            // Version20260226000001AddForeignKeyConstraints.
            //
            // The counterparty_account_id column (no FK) safely holds the
            // synthetic system UUID for audit / statement display purposes.
            if ($account->getBalance()->getAmountMinorUnits() > 0) {
                $this->ledgerRecorder->recordBootstrapCreditEntry(
                    accountId:            $account->getId()->toString(),
                    systemCounterpartyId: self::SYSTEM_COUNTERPARTY_ACCOUNT_ID,
                    transferId:           self::SYSTEM_BOOTSTRAP_TRANSFER_ID,
                    amountMinorUnits:     $account->getBalance()->getAmountMinorUnits(),
                    currency:             $account->getCurrency(),
                    occurredAt:           $account->getCreatedAt(),
                );
            }

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

        foreach ($account->releaseEvents() as $event) {
            $this->dispatcher->dispatch($event);
        }

        $this->logger->info('account.created', [
            'account_id' => $account->getId()->toString(),
            'owner_name' => $account->getOwnerName(),
            'currency'   => $account->getCurrency(),
        ]);

        return AccountDTO::fromAccount($account);
    }
}
