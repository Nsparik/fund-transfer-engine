<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Command\ReverseTransfer;

use App\Module\Transfer\Application\DTO\TransferDTO;
use App\Module\Transfer\Domain\Exception\InvalidTransferStateException;
use App\Module\Transfer\Domain\Exception\TransferNotFoundException;
use App\Module\Transfer\Domain\Repository\TransferRepositoryInterface;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Shared\Application\Port\AccountTransferPort;
use App\Shared\Application\Port\DoubleEntryResult;
use App\Shared\Application\Port\LedgerEntryRecorderPort;
use App\Shared\Application\Port\TaggedEvent;
use App\Shared\Application\Port\TransactionManagerInterface;
use App\Shared\Domain\Exception\AccountNotFoundForTransferException;
use App\Shared\Domain\Exception\AccountRuleViolationException;
use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use App\Shared\Domain\Outbox\OutboxEventSerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles the ReverseTransferCommand use-case.
 *
 * ## What a reversal does
 *   A reversal undoes a COMPLETED double-entry transfer:
 *     1. Credit the source account  (funds returned to sender).
 *     2. Debit  the destination account (funds reclaimed from recipient).
 *     3. Mark the transfer COMPLETED → REVERSED.
 *
 *   All three writes happen in a single atomic database transaction.
 *
 * ## Module boundary
 *   The double-entry is delegated to AccountTransferPort with SOURCE and
 *   DESTINATION swapped: the original destination is debited and the original
 *   source is credited.  No Account domain types are imported here.
 *
 * ## Concurrency safety
 *   The transfer row is loaded with SELECT … FOR UPDATE inside the transaction.
 *   This prevents two concurrent reversal requests from both reading COMPLETED
 *   and both calling reverse() — the second request blocks on the row lock
 *   and then sees the REVERSED status → InvalidTransferStateException → 409.
 *
 * ## Destination insufficient funds
 *   If the destination account has been drained, InsufficientFundsException
 *   is wrapped in AccountRuleViolationException → HTTP 422.
 *   The transfer stays COMPLETED. Caller must use a dispute process.
 *
 * ## Event delivery
 *   AccountDebited, AccountCredited, and TransferReversed are written to the
 *   outbox atomically inside the transaction for durable delivery.
 */
final class ReverseTransferHandler
{
    public function __construct(
        private readonly TransferRepositoryInterface    $transfers,
        private readonly AccountTransferPort            $accountTransferPort,
        private readonly TransactionManagerInterface    $transactionManager,
        private readonly LoggerInterface                $logger,
        private readonly OutboxRepositoryInterface      $outbox,
        private readonly OutboxEventSerializerInterface $serializer,
        private readonly LedgerEntryRecorderPort        $ledgerRecorder,
    ) {}

    /**
     * @throws \App\Module\Transfer\Domain\Exception\TransferNotFoundException       on unknown transfer ID → 404
     * @throws \App\Module\Transfer\Domain\Exception\InvalidTransferStateException  when transfer is not COMPLETED → 409
     * @throws AccountRuleViolationException                                         when destination has insufficient funds → 422
     * @throws AccountNotFoundForTransferException                                   when an account was deleted after the original transfer → 404
     * @throws \InvalidArgumentException                                             on malformed transfer UUID
     */
    public function __invoke(ReverseTransferCommand $command): TransferDTO
    {
        $transferId = TransferId::fromString($command->transferId);

        $transfer      = null;
        $result        = null;

        $this->logger->info('transfer.reverse.start', [
            'transfer_id' => $command->transferId,
        ]);

        try {
            $this->transactionManager->transactional(
                function () use ($transferId, &$transfer, &$result): void {
                    $transfer = $this->transfers->getByIdForUpdate($transferId);

                    $transfer->reverse(); // COMPLETED → REVERSED

                    // SOURCE and DESTINATION are SWAPPED:
                    //   original source      → receives credit (funds returned)
                    //   original destination → receives debit  (funds reclaimed)
                    $result = $this->accountTransferPort->executeDoubleEntry(
                        sourceAccountId:      $transfer->getDestinationAccountId()->toString(),
                        destinationAccountId: $transfer->getSourceAccountId()->toString(),
                        amountMinorUnits:     $transfer->getAmount()->getAmountMinorUnits(),
                        currency:             $transfer->getAmount()->getCurrency(),
                        transferId:           $transfer->getId()->toString(),
                        transferType:         'reversal',
                    );

                    $this->transfers->save($transfer);

                    $this->ledgerRecorder->recordTransferEntries(
                        sourceAccountId:                   $transfer->getDestinationAccountId()->toString(),
                        destinationAccountId:              $transfer->getSourceAccountId()->toString(),
                        transferId:                        $transfer->getId()->toString(),
                        transferType:                      'reversal',
                        amountMinorUnits:                  $transfer->getAmount()->getAmountMinorUnits(),
                        currency:                          $transfer->getAmount()->getCurrency(),
                        sourceBalanceAfterMinorUnits:      $result->sourceBalanceAfterMinorUnits,
                        destinationBalanceAfterMinorUnits: $result->destinationBalanceAfterMinorUnits,
                        occurredAt:                        $transfer->getReversedAt() ?? $transfer->getUpdatedAt(),
                    );

                    foreach ($transfer->peekEvents() as $event) {
                        $this->outbox->save(
                            $this->serializer->serialize(
                                $event,
                                'Transfer',
                                $transfer->getId()->toString(),
                            )
                        );
                    }
                    foreach ($result->events as $tagged) {
                        $this->outbox->save(
                            $this->serializer->serialize(
                                $tagged->event,
                                'Account',
                                $tagged->aggregateId,
                            )
                        );
                    }
                }
            );
        } catch (TransferNotFoundException $e) {
            $this->logger->warning('transfer.reverse.not_found', [
                'transfer_id' => $command->transferId,
                'reason'      => $e->getMessage(),
            ]);
            throw $e;
        } catch (InvalidTransferStateException $e) {
            $this->logger->warning('transfer.reverse.invalid_state', [
                'transfer_id' => $command->transferId,
                'domain_code' => $e->getDomainCode(),
                'reason'      => $e->getMessage(),
            ]);
            throw $e;
        } catch (AccountNotFoundForTransferException $e) {
            // An account involved in the original transfer has since been deleted.
            // This is a data-integrity event and warrants elevated severity.
            $this->logger->error('transfer.reverse.account_not_found', [
                'transfer_id' => $command->transferId,
                'domain_code' => $e->getDomainCode(),
                'reason'      => $e->getMessage(),
            ]);
            throw $e;
        } catch (AccountRuleViolationException $e) {
            $this->logger->warning('transfer.reverse.account_rule_violation', [
                'transfer_id' => $command->transferId,
                'domain_code' => $e->getDomainCode(),
                'reason'      => $e->getMessage(),
            ]);
            throw $e;
        }

        /** @var \App\Module\Transfer\Domain\Model\Transfer $transfer */
        $transfer->releaseEvents();

        $this->logger->info('transfer.reverse.completed', [
            'transfer_id' => $transfer->getId()->toString(),
            'reference'   => $transfer->getReference()->toString(),
        ]);

        return TransferDTO::fromTransfer($transfer);
    }
}
