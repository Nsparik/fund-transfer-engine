<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Service;

use App\Module\Account\Domain\Exception\AccountDomainException;
use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Repository\AccountRepositoryInterface;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Domain\ValueObject\Balance;
use App\Module\Account\Domain\Event\AccountDebited;
use App\Module\Account\Domain\Event\AccountCredited;
use App\Shared\Application\Port\AccountTransferPort;
use App\Shared\Application\Port\DoubleEntryResult;
use App\Shared\Application\Port\TaggedEvent;
use App\Shared\Domain\Exception\AccountNotFoundForTransferException;
use App\Shared\Domain\Exception\AccountRuleViolationException;

/**
 * Account module's implementation of AccountTransferPort.
 *
 * Called from inside the Transfer handler's transaction closure.
 * Acquires pessimistic row locks in deadlock-safe alphabetical UUID order,
 * performs debit + credit, saves both accounts, and returns released events.
 *
 * ## Contract
 *   MUST be called inside an active DB transaction (started by the Transfer handler).
 *   Both account saves participate in the same transaction as the transfer save.
 *
 * ## Lock order
 *   Accounts are locked by alphabetical UUID string comparison to prevent
 *   AB/BA deadlocks when two concurrent transfers involve the same account pair.
 */
final class AccountTransferService implements AccountTransferPort
{
    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function executeDoubleEntry(
        string $sourceAccountId,
        string $destinationAccountId,
        int    $amountMinorUnits,
        string $currency,
        string $transferId,
        string $transferType = 'transfer',
    ): DoubleEntryResult {
        $sourceAccId = AccountId::fromString($sourceAccountId);
        $destAccId   = AccountId::fromString($destinationAccountId);


        [$firstId, $secondId] = strcmp($sourceAccountId, $destinationAccountId) <= 0
            ? [$sourceAccId, $destAccId]
            : [$destAccId, $sourceAccId];

        try {
            $first  = $this->accounts->getByIdForUpdate($firstId);
            $second = $this->accounts->getByIdForUpdate($secondId);
        } catch (AccountNotFoundException $e) {
            throw new AccountNotFoundForTransferException($e);
        }

        if ($first->getId()->toString() === $sourceAccountId) {
            $sourceAccount = $first;
            $destAccount   = $second;
        } else {
            $sourceAccount = $second;
            $destAccount   = $first;
        }

        $amount = new Balance($amountMinorUnits, $currency);

        try {
            $sourceAccount->debit($amount, $transferId, $transferType, $destinationAccountId);
            $destAccount->credit($amount, $transferId, $transferType, $sourceAccountId);
        } catch (AccountDomainException $e) {
            throw new AccountRuleViolationException($e);
        }

        $this->accounts->save($sourceAccount);
        $this->accounts->save($destAccount);

        $sourceEvents = $sourceAccount->releaseEvents();
        $destEvents   = $destAccount->releaseEvents();

        $sourceBalanceAfter = 0;
        $destBalanceAfter   = 0;
        foreach ($sourceEvents as $event) {
            if ($event instanceof AccountDebited) {
                $sourceBalanceAfter = $event->balanceAfter->getAmountMinorUnits();
            }
        }
        foreach ($destEvents as $event) {
            if ($event instanceof AccountCredited) {
                $destBalanceAfter = $event->balanceAfter->getAmountMinorUnits();
            }
        }

        // Tag each event with the UUID of the account that raised it so the Transfer
        // handler can store the correct aggregate_id in the outbox row — essential for
        // per-account event replay and future microservice extraction.
        return new DoubleEntryResult(
            sourceBalanceAfterMinorUnits:      $sourceBalanceAfter,
            destinationBalanceAfterMinorUnits: $destBalanceAfter,
            events:                            [
                ...array_map(fn ($e) => new TaggedEvent($e, $sourceAccountId), $sourceEvents),
                ...array_map(fn ($e) => new TaggedEvent($e, $destinationAccountId), $destEvents),
            ],
        );
    }
}
