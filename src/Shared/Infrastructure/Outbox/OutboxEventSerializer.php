<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Module\Account\Domain\Event\AccountClosed;
use App\Module\Account\Domain\Event\AccountCreated;
use App\Module\Account\Domain\Event\AccountCredited;
use App\Module\Account\Domain\Event\AccountDebited;
use App\Module\Account\Domain\Event\AccountFrozen;
use App\Module\Account\Domain\Event\AccountUnfrozen;
use App\Module\Account\Domain\ValueObject\AccountId as AccountAccountId;
use App\Module\Account\Domain\ValueObject\Balance as AccountBalance;
use App\Module\Transfer\Domain\Event\TransferCompleted;
use App\Module\Transfer\Domain\Event\TransferFailed;
use App\Module\Transfer\Domain\Event\TransferInitiated;
use App\Module\Transfer\Domain\Event\TransferReversed;
use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;
use App\Shared\Domain\Outbox\OutboxEvent;
use App\Shared\Domain\Outbox\OutboxEventId;

/**
 * Converts domain events to/from outbox_events table payloads.
 *
 * Domain event classes remain free of serialization concerns (SRP).
 * Covers both Transfer and Account lifecycle events.
 *
 * Adding a new event type (exactly 3 code locations + tests):
 *   1. Add an `instanceof` case to the serialize() match.
 *   2. Add an `instanceof` case to the deserialize() match.
 *   3. Add the class to SUPPORTED_TYPES (used in the unsupported-type error message).
 *   4. Add private serializeXxx() and deserializeXxx() helper methods.
 *   5. Add a round-trip test in OutboxEventSerializerTest.
 *
 * NOTE: occurredAt extraction requires NO update — all domain events expose a
 *       public DateTimeImmutable $occurredAt and the property is accessed directly.
 */
final class OutboxEventSerializer implements \App\Shared\Domain\Outbox\OutboxEventSerializerInterface
{
    private const SUPPORTED_TYPES = [
        TransferInitiated::class,
        TransferCompleted::class,
        TransferFailed::class,
        TransferReversed::class,
        AccountCreated::class,
        AccountClosed::class,
        AccountFrozen::class,
        AccountUnfrozen::class,
        AccountDebited::class,
        AccountCredited::class,
    ];

    /**
     * Convert a domain event object into an OutboxEvent value ready for DB persistence.
     *
     * @throws \InvalidArgumentException when the event type is not supported
     */
    public function serialize(
        object $event,
        string $aggregateType,
        string $aggregateId,
    ): OutboxEvent {
        $payload = match (true) {
            $event instanceof TransferInitiated => $this->serializeTransferInitiated($event),
            $event instanceof TransferCompleted => $this->serializeTransferCompleted($event),
            $event instanceof TransferFailed    => $this->serializeTransferFailed($event),
            $event instanceof TransferReversed  => $this->serializeTransferReversed($event),
            $event instanceof AccountCreated    => $this->serializeAccountCreated($event),
            $event instanceof AccountClosed     => $this->serializeAccountClosed($event),
            $event instanceof AccountFrozen     => $this->serializeAccountFrozen($event),
            $event instanceof AccountUnfrozen   => $this->serializeAccountUnfrozen($event),
            $event instanceof AccountDebited    => $this->serializeAccountDebited($event),
            $event instanceof AccountCredited   => $this->serializeAccountCredited($event),
            default => throw new \InvalidArgumentException(
                sprintf(
                    'OutboxEventSerializer: unsupported event type "%s". '
                    . 'Supported: %s.',
                    $event::class,
                    implode(', ', self::SUPPORTED_TYPES),
                ),
            ),
        };

        // All supported domain events expose a public DateTimeImmutable $occurredAt.
        // The serialize() match above already rejects unknown event types, so this
        // cast is safe — and avoids a hidden 4th maintenance point every time a
        // new event type is added.
        /** @var object{occurredAt: \DateTimeImmutable} $event */
        $occurredAt = $event->occurredAt;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new OutboxEvent(
            id:            OutboxEventId::generate(),
            aggregateType: $aggregateType,
            aggregateId:   $aggregateId,
            eventType:     $event::class,
            payload:       $payload,
            occurredAt:    $occurredAt,
            createdAt:     $now,
        );
    }

    /**
     * Reconstruct a domain event object from an outbox row for in-process dispatch.
     *
     * @throws \InvalidArgumentException when the event type is not supported
     * @throws \UnexpectedValueException when the payload is malformed
     */
    public function deserialize(OutboxEvent $row): object
    {
        return match ($row->eventType) {
            TransferInitiated::class => $this->deserializeTransferInitiated($row->payload),
            TransferCompleted::class => $this->deserializeTransferCompleted($row->payload),
            TransferFailed::class    => $this->deserializeTransferFailed($row->payload),
            TransferReversed::class  => $this->deserializeTransferReversed($row->payload),
            AccountCreated::class   => $this->deserializeAccountCreated($row->payload),
            AccountClosed::class     => $this->deserializeAccountClosed($row->payload),
            AccountFrozen::class     => $this->deserializeAccountFrozen($row->payload),
            AccountUnfrozen::class   => $this->deserializeAccountUnfrozen($row->payload),
            AccountDebited::class    => $this->deserializeAccountDebited($row->payload),
            AccountCredited::class   => $this->deserializeAccountCredited($row->payload),
            default => throw new \InvalidArgumentException(
                sprintf(
                    'OutboxEventSerializer: cannot deserialize unknown event type "%s".',
                    $row->eventType,
                ),
            ),
        };
    }

    /** @return array<string, mixed> */
    private function serializeTransferInitiated(TransferInitiated $e): array
    {
        return [
            'transfer_id'            => $e->transferId->toString(),
            'reference'              => $e->reference->toString(),
            'source_account_id'      => $e->sourceAccountId->toString(),
            'destination_account_id' => $e->destinationAccountId->toString(),
            'amount_minor_units'     => $e->amount->getAmountMinorUnits(),
            'currency'               => $e->amount->getCurrency(),
            'occurred_at'            => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTransferCompleted(TransferCompleted $e): array
    {
        return [
            'transfer_id'            => $e->transferId->toString(),
            'reference'              => $e->reference->toString(),
            'source_account_id'      => $e->sourceAccountId->toString(),
            'destination_account_id' => $e->destinationAccountId->toString(),
            'amount_minor_units'     => $e->amount->getAmountMinorUnits(),
            'currency'               => $e->amount->getCurrency(),
            'occurred_at'            => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTransferFailed(TransferFailed $e): array
    {
        return [
            'transfer_id'            => $e->transferId->toString(),
            'reference'              => $e->reference->toString(),
            'source_account_id'      => $e->sourceAccountId->toString(),
            'destination_account_id' => $e->destinationAccountId->toString(),
            'amount_minor_units'     => $e->amount->getAmountMinorUnits(),
            'currency'               => $e->amount->getCurrency(),
            'failure_code'           => $e->failureCode,
            'failure_reason'         => $e->failureReason,
            'occurred_at'            => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTransferReversed(TransferReversed $e): array
    {
        return [
            'transfer_id'            => $e->transferId->toString(),
            'reference'              => $e->reference->toString(),
            'source_account_id'      => $e->sourceAccountId->toString(),
            'destination_account_id' => $e->destinationAccountId->toString(),
            'amount_minor_units'     => $e->amount->getAmountMinorUnits(),
            'currency'               => $e->amount->getCurrency(),
            'occurred_at'            => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $p */
    private function deserializeTransferInitiated(array $p): TransferInitiated
    {
        return new TransferInitiated(
            transferId:           TransferId::fromString((string) $p['transfer_id']),
            reference:            TransferReference::fromString((string) $p['reference']),
            sourceAccountId:      AccountId::fromString((string) $p['source_account_id']),
            destinationAccountId: AccountId::fromString((string) $p['destination_account_id']),
            amount:               new Money((int) $p['amount_minor_units'], (string) $p['currency']),
            occurredAt:           new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @param array<string, mixed> $p */
    private function deserializeTransferCompleted(array $p): TransferCompleted
    {
        return new TransferCompleted(
            transferId:           TransferId::fromString((string) $p['transfer_id']),
            reference:            TransferReference::fromString((string) $p['reference']),
            sourceAccountId:      AccountId::fromString((string) $p['source_account_id']),
            destinationAccountId: AccountId::fromString((string) $p['destination_account_id']),
            amount:               new Money((int) $p['amount_minor_units'], (string) $p['currency']),
            occurredAt:           new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @param array<string, mixed> $p */
    private function deserializeTransferFailed(array $p): TransferFailed
    {
        return new TransferFailed(
            transferId:           TransferId::fromString((string) $p['transfer_id']),
            reference:            TransferReference::fromString((string) $p['reference']),
            sourceAccountId:      AccountId::fromString((string) $p['source_account_id']),
            destinationAccountId: AccountId::fromString((string) $p['destination_account_id']),
            amount:               new Money((int) $p['amount_minor_units'], (string) $p['currency']),
            failureCode:          (string) $p['failure_code'],
            failureReason:        (string) $p['failure_reason'],
            occurredAt:           new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @param array<string, mixed> $p */
    private function deserializeTransferReversed(array $p): TransferReversed
    {
        return new TransferReversed(
            transferId:           TransferId::fromString((string) $p['transfer_id']),
            reference:            TransferReference::fromString((string) $p['reference']),
            sourceAccountId:      AccountId::fromString((string) $p['source_account_id']),
            destinationAccountId: AccountId::fromString((string) $p['destination_account_id']),
            amount:               new Money((int) $p['amount_minor_units'], (string) $p['currency']),
            occurredAt:           new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, mixed> */
    private function serializeAccountCreated(AccountCreated $e): array
    {
        return [
            'account_id'                  => $e->accountId->toString(),
            'owner_name'                  => $e->ownerName,
            'initial_balance_minor_units' => $e->initialBalance->getAmountMinorUnits(),
            'currency'                    => $e->initialBalance->getCurrency(),
            'occurred_at'                 => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $p */
    private function deserializeAccountCreated(array $p): AccountCreated
    {
        return new AccountCreated(
            accountId:      AccountAccountId::fromString((string) $p['account_id']),
            ownerName:      (string) $p['owner_name'],
            initialBalance: new AccountBalance((int) $p['initial_balance_minor_units'], (string) $p['currency']),
            occurredAt:     new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, mixed> */
    private function serializeAccountClosed(AccountClosed $e): array
    {
        return [
            'account_id'  => $e->accountId->toString(),
            'occurred_at' => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $p */
    private function deserializeAccountClosed(array $p): AccountClosed
    {
        return new AccountClosed(
            accountId:  AccountAccountId::fromString((string) $p['account_id']),
            occurredAt: new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, mixed> */
    private function serializeAccountFrozen(AccountFrozen $e): array
    {
        return [
            'account_id'  => $e->accountId->toString(),
            'occurred_at' => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $p */
    private function deserializeAccountFrozen(array $p): AccountFrozen
    {
        return new AccountFrozen(
            accountId:  AccountAccountId::fromString((string) $p['account_id']),
            occurredAt: new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, mixed> */
    private function serializeAccountUnfrozen(AccountUnfrozen $e): array
    {
        return [
            'account_id'  => $e->accountId->toString(),
            'occurred_at' => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $p */
    private function deserializeAccountUnfrozen(array $p): AccountUnfrozen
    {
        return new AccountUnfrozen(
            accountId:  AccountAccountId::fromString((string) $p['account_id']),
            occurredAt: new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, mixed> */
    private function serializeAccountDebited(AccountDebited $e): array
    {
        return [
            'account_id'               => $e->accountId->toString(),
            'amount_minor_units'       => $e->amount->getAmountMinorUnits(),
            'currency'                 => $e->amount->getCurrency(),
            'balance_after_minor_units' => $e->balanceAfter->getAmountMinorUnits(),
            'transfer_id'              => $e->transferId,
            'transfer_type'            => $e->transferType,
            'counterparty_account_id'  => $e->counterpartyAccountId,
            'occurred_at'              => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $p */
    private function deserializeAccountDebited(array $p): AccountDebited
    {
        $currency = (string) $p['currency'];
        return new AccountDebited(
            accountId:              AccountAccountId::fromString((string) $p['account_id']),
            amount:                 new AccountBalance((int) $p['amount_minor_units'], $currency),
            balanceAfter:           new AccountBalance((int) $p['balance_after_minor_units'], $currency),
            transferId:             (string) $p['transfer_id'],
            transferType:           (string) $p['transfer_type'],
            counterpartyAccountId:  (string) $p['counterparty_account_id'],
            occurredAt:             new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, mixed> */
    private function serializeAccountCredited(AccountCredited $e): array
    {
        return [
            'account_id'               => $e->accountId->toString(),
            'amount_minor_units'       => $e->amount->getAmountMinorUnits(),
            'currency'                 => $e->amount->getCurrency(),
            'balance_after_minor_units' => $e->balanceAfter->getAmountMinorUnits(),
            'transfer_id'              => $e->transferId,
            'transfer_type'            => $e->transferType,
            'counterparty_account_id'  => $e->counterpartyAccountId,
            'occurred_at'              => $e->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $p */
    private function deserializeAccountCredited(array $p): AccountCredited
    {
        $currency = (string) $p['currency'];
        return new AccountCredited(
            accountId:              AccountAccountId::fromString((string) $p['account_id']),
            amount:                 new AccountBalance((int) $p['amount_minor_units'], $currency),
            balanceAfter:           new AccountBalance((int) $p['balance_after_minor_units'], $currency),
            transferId:             (string) $p['transfer_id'],
            transferType:           (string) $p['transfer_type'],
            counterpartyAccountId:  (string) $p['counterparty_account_id'],
            occurredAt:             new \DateTimeImmutable((string) $p['occurred_at'], new \DateTimeZone('UTC')),
        );
    }
}
