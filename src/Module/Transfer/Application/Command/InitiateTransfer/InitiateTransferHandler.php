<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Command\InitiateTransfer;

use App\Module\Transfer\Application\DTO\TransferDTO;
use App\Module\Transfer\Domain\Model\Transfer;
use App\Module\Transfer\Domain\Repository\TransferRepositoryInterface;
use App\Module\Transfer\Domain\ValueObject\AccountId as TransferAccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
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
 * Handles the InitiateTransferCommand use-case.
 *
 * ## Transaction scope
 *   Everything inside transactional() is one atomic DB write:
 *     1. PENDING → PROCESSING state transition.
 *     2. AccountTransferPort::executeDoubleEntry() — locks both accounts
 *        FOR UPDATE in alphabetical UUID order, debits source, credits
 *        destination, saves both accounts.
 *     3. PROCESSING → COMPLETED state transition.
 *     4. Transfer row persisted (upsert).
 *     5. Two LedgerEntry rows written (DEBIT on source, CREDIT on destination).
 *     6. All domain events written to the outbox.
 *
 *   On AccountNotFoundForTransferException:
 *     — Transaction rolls back; no FAILED record is saved (invalid input).
 *     — Re-thrown → HTTP 404.
 *
 *   On AccountRuleViolationException (frozen, closed, insufficient funds,
 *   currency mismatch):
 *     — Transaction rolls back; balances untouched.
 *     — A second transaction saves a FAILED transfer for audit.
 *     — Re-thrown → HTTP 409 or 422.
 *
 * ## Module boundary
 *   The Transfer module imports no Account or Ledger types.
 *   All cross-module work is delegated via ports (AccountTransferPort,
 *   LedgerEntryRecorderPort) injected at construction time.
 *
 * ## Event delivery
 *   AccountDebited, AccountCredited, TransferInitiated, TransferCompleted are
 *   written to outbox_events atomically so delivery is guaranteed even if the
 *   process dies after the DB commit.
 */
final class InitiateTransferHandler
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
     * @throws \App\Module\Transfer\Domain\Exception\SameAccountTransferException
     * @throws \App\Module\Transfer\Domain\Exception\InvalidTransferAmountException
     * @throws AccountNotFoundForTransferException  on unknown source or destination → HTTP 404
     * @throws AccountRuleViolationException         on business-rule violations (re-thrown after FAILED save)
     * @throws \InvalidArgumentException             on malformed UUIDs or invalid currency
     */
    public function __invoke(InitiateTransferCommand $command): TransferDTO
    {
        $transfer = Transfer::initiate(
            id:                   TransferId::generate(),
            sourceAccountId:      TransferAccountId::fromString($command->sourceAccountId),
            destinationAccountId: TransferAccountId::fromString($command->destinationAccountId),
            amount:               new Money($command->amountMinorUnits, $command->currency),
            description:          $command->description,
            idempotencyKey:       $command->idempotencyKey,
        );

        $this->logger->info('transfer.initiate.start', [
            'transfer_id'            => $transfer->getId()->toString(),
            'source_account_id'      => $command->sourceAccountId,
            'destination_account_id' => $command->destinationAccountId,
            'amount_minor_units'     => $command->amountMinorUnits,
            'currency'               => $command->currency,
        ]);

        $result           = null;
        $existingTransfer = null;

        try {
            $this->transactionManager->transactional(
                function () use ($transfer, $command, &$result, &$existingTransfer): void {
                    // ── DB-level idempotency guard ─────────────────────────────────────
                    //
                    // Closes the crash-after-commit window.
                    //
                    // Scenario: the transfer transaction committed (money moved) but the
                    // PHP process died before IdempotencySubscriber::onResponse() could
                    // write the idempotency_keys row.  The MySQL connection closed, so
                    // GET_LOCK was auto-released.  The client retries with the same key;
                    // the HTTP cache is cold (record was never saved); the handler is
                    // entered again.  Without this check the double-entry would execute
                    // twice — a double-debit.
                    //
                    // With this check: the retry finds the already-committed Transfer by
                    // idempotency_key inside the same new transaction, sets $existingTransfer,
                    // and returns from the closure.  The transaction commits as a no-op.
                    // The outer method detects $existingTransfer !== null and returns its
                    // DTO without any money movement.
                    if ($command->idempotencyKey !== null) {
                        $existingTransfer = $this->transfers->findByIdempotencyKey(
                            $command->idempotencyKey
                        );
                        if ($existingTransfer !== null) {
                            $this->logger->info('transfer.initiate.idempotent_return', [
                                'existing_transfer_id' => $existingTransfer->getId()->toString(),
                                'idempotency_key'      => $command->idempotencyKey,
                            ]);
                            return; // No-op transaction — existing transfer already committed.
                        }
                    }

                    $transfer->markAsProcessing();

                    $result = $this->accountTransferPort->executeDoubleEntry(
                        sourceAccountId:      $command->sourceAccountId,
                        destinationAccountId: $command->destinationAccountId,
                        amountMinorUnits:     $command->amountMinorUnits,
                        currency:             $command->currency,
                        transferId:           $transfer->getId()->toString(),
                    );

                    $transfer->complete();
                    $this->transfers->save($transfer);

                    $this->ledgerRecorder->recordTransferEntries(
                        sourceAccountId:                   $command->sourceAccountId,
                        destinationAccountId:              $command->destinationAccountId,
                        transferId:                        $transfer->getId()->toString(),
                        transferType:                      'transfer',
                        amountMinorUnits:                  $command->amountMinorUnits,
                        currency:                          $command->currency,
                        sourceBalanceAfterMinorUnits:      $result->sourceBalanceAfterMinorUnits,
                        destinationBalanceAfterMinorUnits: $result->destinationBalanceAfterMinorUnits,
                        occurredAt:                        $transfer->getCompletedAt() ?? $transfer->getUpdatedAt(),
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
        } catch (AccountNotFoundForTransferException $e) {
            // Do NOT record a FAILED transfer: there is no valid transfer to fail.
            $this->logger->warning('transfer.initiate.account_not_found', [
                'transfer_id' => $transfer->getId()->toString(),
                'domain_code' => $e->getDomainCode(),
                'reason'      => $e->getMessage(),
            ]);
            throw $e;
        } catch (AccountRuleViolationException $e) {
            $transfer->fail($e->getDomainCode(), $e->getMessage());

            $this->logger->warning('transfer.initiate.failed', [
                'transfer_id'  => $transfer->getId()->toString(),
                'failure_code' => $e->getDomainCode(),
                'reason'       => $e->getMessage(),
            ]);

            try {
                $this->transactionManager->transactional(function () use ($transfer): void {
                    $this->transfers->save($transfer);

                    foreach ($transfer->peekEvents() as $event) {
                        $this->outbox->save(
                            $this->serializer->serialize(
                                $event,
                                'Transfer',
                                $transfer->getId()->toString(),
                            )
                        );
                    }
                });

                $transfer->releaseEvents();
            } catch (\Throwable $innerThrowable) {
                // Full save (transfer + outbox) failed — e.g. outbox table
                // unavailable (disk full, schema drift).  Retry with a minimal
                // transaction that saves ONLY the FAILED transfer row so the
                // financial audit record is durable even when outbox event
                // delivery cannot be guaranteed.
                $this->logger->error('transfer.initiate.failed_save_error', [
                    'transfer_id'       => $transfer->getId()->toString(),
                    'failure_code'      => $e->getDomainCode(),
                    'exception_class'   => $innerThrowable::class,
                    'exception_message' => $innerThrowable->getMessage(),
                ]);

                try {
                    $this->transactionManager->transactional(
                        function () use ($transfer): void {
                            $this->transfers->save($transfer);
                            // Outbox writes intentionally omitted on this
                            // degraded path — audit durability takes priority
                            // over outbox event delivery.
                        }
                    );
                } catch (\Throwable $minimalThrowable) {
                    // Even the bare-minimum save failed — log every recoverable
                    // field so ops can reconstruct the audit record manually.
                    $this->logger->critical('transfer.initiate.failed_record_lost', [
                        'transfer_id'             => $transfer->getId()->toString(),
                        'source_account_id'       => $command->sourceAccountId,
                        'destination_account_id'  => $command->destinationAccountId,
                        'failure_code'            => $e->getDomainCode(),
                        'failure_reason'          => $e->getMessage(),
                        'initial_exception_class' => $innerThrowable::class,
                        'initial_exception_msg'   => $innerThrowable->getMessage(),
                        'minimal_exception_class' => $minimalThrowable::class,
                        'minimal_exception_msg'   => $minimalThrowable->getMessage(),
                    ]);
                }

                $transfer->releaseEvents(); // drain to prevent stale in-process dispatch
            }

            throw $e;
        }

        // Idempotent retry path: a committed transfer was found by idempotency_key inside
        // the transaction (crash-after-commit recovery).  Drain the in-memory domain events
        // from the discarded new Transfer object to prevent stale in-process dispatch, then
        // return the existing DTO without any further money movement.
        if ($existingTransfer !== null) {
            $transfer->releaseEvents();
            return TransferDTO::fromTransfer($existingTransfer);
        }

        $transfer->releaseEvents();

        $this->logger->info('transfer.initiate.completed', [
            'transfer_id' => $transfer->getId()->toString(),
            'reference'   => $transfer->getReference()->toString(),
        ]);

        return TransferDTO::fromTransfer($transfer);
    }
}
