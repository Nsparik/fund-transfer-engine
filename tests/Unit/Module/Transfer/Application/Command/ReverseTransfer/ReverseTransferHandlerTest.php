<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Transfer\Application\Command\ReverseTransfer;

use App\Module\Account\Domain\Event\AccountCredited;
use App\Module\Account\Domain\Event\AccountDebited;
use App\Module\Account\Domain\ValueObject\AccountId as AccountAccountId;
use App\Module\Account\Domain\ValueObject\Balance;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferCommand;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferHandler;
use App\Module\Transfer\Domain\Event\TransferReversed;
use App\Module\Transfer\Domain\Exception\InvalidTransferStateException;
use App\Module\Transfer\Domain\Exception\TransferNotFoundException;
use App\Module\Transfer\Domain\Model\Transfer;
use App\Module\Transfer\Domain\Model\TransferStatus;
use App\Module\Transfer\Domain\Repository\TransferRepositoryInterface;
use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Domain\ValueObject\TransferReference;
use App\Shared\Application\Port\AccountTransferPort;
use App\Shared\Application\Port\DoubleEntryResult;
use App\Shared\Application\Port\LedgerEntryRecorderPort;
use App\Shared\Application\Port\TaggedEvent;
use App\Shared\Application\Port\TransactionManagerInterface;
use App\Shared\Domain\Exception\AccountNotFoundForTransferException;
use App\Shared\Domain\Exception\AccountRuleViolationException;
use App\Shared\Domain\Outbox\OutboxRepositoryInterface;
use App\Shared\Infrastructure\Outbox\OutboxEventSerializer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ReverseTransferHandler.
 *
 * Uses in-memory fakes for all infrastructure dependencies so tests run
 * without a database.  Transaction semantics are simulated by the
 * ImmediateTransactionManager fake.
 *
 * The handler uses AccountTransferPort — Account domain types are NOT imported here.  Double-entry and rule violations are exercised by
 * controlling what the AccountTransferPort mock returns or throws.
 */
final class ReverseTransferHandlerTest extends TestCase
{
    private TransferId        $transferId;
    private AccountId         $sourceId;
    private AccountId         $destId;
    private TransferReference $reference;

    protected function setUp(): void
    {
        $this->transferId = TransferId::generate();
        $this->sourceId   = AccountId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $this->destId     = AccountId::fromString('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $this->reference  = TransferReference::fromString('TXN-20260224-AABBCCDDEEFF');
    }

    // ── Happy path ─────────────────────────────────────────────────────────────

    public function testReversalCompletesAndReturnsReversedStatus(): void
    {
        $transfer = $this->completedTransfer(1_000);

        // Real Account events are required since they go through OutboxEventSerializer.
        $now          = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $accountEvent1 = new AccountCredited(
            accountId:             AccountAccountId::fromString($this->sourceId->toString()),
            amount:                new Balance(1_000, 'USD'),
            balanceAfter:          new Balance(11_000, 'USD'),
            transferId:            $this->transferId->toString(),
            transferType:          'reversal',
            counterpartyAccountId: $this->destId->toString(),
            occurredAt:            $now,
        );
        $accountEvent2 = new AccountDebited(
            accountId:             AccountAccountId::fromString($this->destId->toString()),
            amount:                new Balance(1_000, 'USD'),
            balanceAfter:          new Balance(4_000, 'USD'),
            transferId:            $this->transferId->toString(),
            transferType:          'reversal',
            counterpartyAccountId: $this->sourceId->toString(),
            occurredAt:            $now,
        );

        $savedTransfers   = [];
        $outboxMessages   = [];

        $outbox = $this->createMock(\App\Shared\Domain\Outbox\OutboxRepositoryInterface::class);
        $outbox->method('save')->willReturnCallback(function (\App\Shared\Domain\Outbox\OutboxEvent $msg) use (&$outboxMessages): void {
            $outboxMessages[] = $msg;
        });

        $handler = $this->buildHandlerWithOutbox(
            transfer:       $transfer,
            accountEvents:  [$accountEvent1, $accountEvent2],
            onSaveTransfer: function (Transfer $t) use (&$savedTransfers): void {
                $savedTransfers[] = $t;
            },
            outbox:         $outbox,
        );

        $dto = $handler(new ReverseTransferCommand($this->transferId->toString()));

        // Transfer must be REVERSED.
        self::assertSame(TransferStatus::REVERSED->value, $dto->status);
        self::assertNotNull($dto->reversedAt);

        // Transfer was saved.
        self::assertCount(1, $savedTransfers);

        // Account events go through outbox (not dispatched inline).
        // Outbox should have: TransferReversed + AccountCredited + AccountDebited = 3 messages.
        self::assertGreaterThanOrEqual(3, count($outboxMessages), 'Expected at least 3 outbox messages (TransferReversed + 2 account events)');

        $eventTypes = array_map(fn (\App\Shared\Domain\Outbox\OutboxEvent $m) => $m->eventType, $outboxMessages);
        self::assertContains(\App\Module\Transfer\Domain\Event\TransferReversed::class, $eventTypes, 'TransferReversed must be in outbox');
        self::assertContains(AccountCredited::class, $eventTypes, 'AccountCredited must be in outbox');
        self::assertContains(AccountDebited::class, $eventTypes, 'AccountDebited must be in outbox');
    }

    public function testReversedAtIsPopulatedOnDTO(): void
    {
        $transfer = $this->completedTransfer(500);

        $handler = $this->buildHandler(transfer: $transfer);

        $dto = $handler(new ReverseTransferCommand($this->transferId->toString()));

        self::assertNotNull($dto->reversedAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $dto->reversedAt);
    }

    // ── AccountTransferPort receives swapped source/destination ───────────────

    public function testPortIsCalledWithSwappedSourceAndDestination(): void
    {
        $transfer = $this->completedTransfer(1_000);

        $capturedSource = null;
        $capturedDest   = null;

        $port = $this->createMock(AccountTransferPort::class);
        $port->expects(self::once())
            ->method('executeDoubleEntry')
            ->willReturnCallback(function (
                string $sourceAccountId,
                string $destinationAccountId,
            ) use (&$capturedSource, &$capturedDest): DoubleEntryResult {
                $capturedSource = $sourceAccountId;
                $capturedDest   = $destinationAccountId;
                return new DoubleEntryResult(0, 0, []);
            });

        $handler = $this->buildHandlerWithPort(transfer: $transfer, port: $port);
        $handler(new ReverseTransferCommand($this->transferId->toString()));

        // For reversal: port's "source" = original destination, port's "destination" = original source.
        self::assertSame($this->destId->toString(), $capturedSource, 'Port source should be the original destination');
        self::assertSame($this->sourceId->toString(), $capturedDest, 'Port destination should be the original source');
    }

    // ── State-machine guards ───────────────────────────────────────────────────

    public function testReversingPendingTransferThrows(): void
    {
        $transfer = $this->pendingTransfer(500);
        $handler  = $this->buildHandler(transfer: $transfer);

        $this->expectException(InvalidTransferStateException::class);
        $handler(new ReverseTransferCommand($this->transferId->toString()));
    }

    public function testReversingAlreadyReversedTransferThrows(): void
    {
        $transfer = $this->reversedTransfer(500);
        $handler  = $this->buildHandler(transfer: $transfer);

        $this->expectException(InvalidTransferStateException::class);
        $handler(new ReverseTransferCommand($this->transferId->toString()));
    }

    public function testReversingFailedTransferThrows(): void
    {
        $transfer = $this->failedTransfer(500);
        $handler  = $this->buildHandler(transfer: $transfer);

        $this->expectException(InvalidTransferStateException::class);
        $handler(new ReverseTransferCommand($this->transferId->toString()));
    }

    // ── Unknown transfer ───────────────────────────────────────────────────────

    public function testUnknownTransferIdThrowsTransferNotFoundException(): void
    {
        $handler = $this->buildHandlerWithNoTransfer();

        $this->expectException(TransferNotFoundException::class);
        $handler(new ReverseTransferCommand($this->transferId->toString()));
    }

    // ── AccountRuleViolationException propagation ─────────────────────────────

    public function testAccountRuleViolationFromPortPropagates(): void
    {
        $transfer   = $this->completedTransfer(1_000);
        $innerCause = new \App\Module\Account\Domain\Exception\InsufficientFundsException('Not enough funds');

        $port = $this->createStub(AccountTransferPort::class);
        $port->method('executeDoubleEntry')
            ->willThrowException(new AccountRuleViolationException($innerCause));

        $handler = $this->buildHandlerWithPort(transfer: $transfer, port: $port);

        $this->expectException(AccountRuleViolationException::class);
        $handler(new ReverseTransferCommand($this->transferId->toString()));
    }

    public function testAccountNotFoundFromPortPropagates(): void
    {
        $transfer   = $this->completedTransfer(1_000);
        $innerCause = new \App\Module\Account\Domain\Exception\AccountNotFoundException('Account gone');

        $port = $this->createStub(AccountTransferPort::class);
        $port->method('executeDoubleEntry')
            ->willThrowException(new AccountNotFoundForTransferException($innerCause));

        $handler = $this->buildHandlerWithPort(transfer: $transfer, port: $port);

        $this->expectException(AccountNotFoundForTransferException::class);
        $handler(new ReverseTransferCommand($this->transferId->toString()));
    }

    public function testPortIsCalledWithReversalTransferType(): void
    {
        $transfer = $this->completedTransfer(1_000);

        $capturedType = null;
        $port         = $this->createMock(AccountTransferPort::class);
        $port->expects(self::once())
            ->method('executeDoubleEntry')
            ->willReturnCallback(function (
                string $src, string $dst, int $amount, string $currency,
                string $tid, string $type
            ) use (&$capturedType): DoubleEntryResult {
                $capturedType = $type;
                return new DoubleEntryResult(0, 0, []);
            });

        $handler = $this->buildHandlerWithPort(transfer: $transfer, port: $port);
        $handler(new ReverseTransferCommand($this->transferId->toString()));

        self::assertSame('reversal', $capturedType, 'ReverseTransferHandler must pass transferType=reversal to the port');
    }

    // ── Events go through outbox after transaction commits ────────────────────

    public function testAccountEventsGoThroughOutboxNotInlineDispatcher(): void
    {
        $transfer     = $this->completedTransfer(1_000);
        $now          = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $accountEvent = new AccountCredited(
            accountId:             AccountAccountId::fromString($this->sourceId->toString()),
            amount:                new Balance(1_000, 'USD'),
            balanceAfter:          new Balance(11_000, 'USD'),
            transferId:            $this->transferId->toString(),
            transferType:          'reversal',
            counterpartyAccountId: $this->destId->toString(),
            occurredAt:            $now,
        );

        $outboxMessages = [];
        $outbox         = $this->createMock(\App\Shared\Domain\Outbox\OutboxRepositoryInterface::class);
        $outbox->method('save')->willReturnCallback(function (\App\Shared\Domain\Outbox\OutboxEvent $msg) use (&$outboxMessages): void {
            $outboxMessages[] = $msg;
        });

        $handler = $this->buildHandlerWithOutbox(
            transfer:      $transfer,
            accountEvents: [$accountEvent],
            outbox:        $outbox,
        );

        $handler(new ReverseTransferCommand($this->transferId->toString()));

        // AccountCredited must be in outbox.
        $eventTypes = array_map(fn (\App\Shared\Domain\Outbox\OutboxEvent $m) => $m->eventType, $outboxMessages);
        self::assertContains(AccountCredited::class, $eventTypes, 'AccountCredited must be written to outbox, not dispatched inline');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function completedTransfer(int $amountMinorUnits): Transfer
    {
        return Transfer::reconstitute(
            id:                   $this->transferId,
            reference:            $this->reference,
            sourceAccountId:      $this->sourceId,
            destinationAccountId: $this->destId,
            amount:               new Money($amountMinorUnits, 'USD'),
            status:               TransferStatus::COMPLETED,
            createdAt:            new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            updatedAt:            new \DateTimeImmutable('2026-01-01T00:00:01', new \DateTimeZone('UTC')),
        );
    }

    private function pendingTransfer(int $amountMinorUnits): Transfer
    {
        return Transfer::reconstitute(
            id:                   $this->transferId,
            reference:            $this->reference,
            sourceAccountId:      $this->sourceId,
            destinationAccountId: $this->destId,
            amount:               new Money($amountMinorUnits, 'USD'),
            status:               TransferStatus::PENDING,
            createdAt:            new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            updatedAt:            new \DateTimeImmutable('2026-01-01T00:00:01', new \DateTimeZone('UTC')),
        );
    }

    private function failedTransfer(int $amountMinorUnits): Transfer
    {
        return Transfer::reconstitute(
            id:                   $this->transferId,
            reference:            $this->reference,
            sourceAccountId:      $this->sourceId,
            destinationAccountId: $this->destId,
            amount:               new Money($amountMinorUnits, 'USD'),
            status:               TransferStatus::FAILED,
            createdAt:            new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            updatedAt:            new \DateTimeImmutable('2026-01-01T00:00:01', new \DateTimeZone('UTC')),
            failureCode:          'INSUFFICIENT_FUNDS',
            failureReason:        'Not enough balance',
        );
    }

    private function reversedTransfer(int $amountMinorUnits): Transfer
    {
        return Transfer::reconstitute(
            id:                   $this->transferId,
            reference:            $this->reference,
            sourceAccountId:      $this->sourceId,
            destinationAccountId: $this->destId,
            amount:               new Money($amountMinorUnits, 'USD'),
            status:               TransferStatus::REVERSED,
            createdAt:            new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC')),
            updatedAt:            new \DateTimeImmutable('2026-01-01T00:00:01', new \DateTimeZone('UTC')),
        );
    }

    /**
     * Build a handler with default stubs and optional callbacks.
     *
     * @param list<object> $accountEvents  Events the port returns (simulates Account domain events)
     */
    private function buildHandler(
        Transfer $transfer,
        array $accountEvents          = [],
        ?callable $onSaveTransfer     = null,
    ): ReverseTransferHandler {
        $port = $this->createStub(AccountTransferPort::class);
        $port->method('executeDoubleEntry')->willReturn(
            new DoubleEntryResult(0, 0, array_map(
                fn ($e) => new TaggedEvent($e, $this->sourceId->toString()),
                $accountEvents,
            ))
        );

        return $this->buildHandlerWithPort(
            transfer:       $transfer,
            port:           $port,
            onSaveTransfer: $onSaveTransfer,
        );
    }

    private function buildHandlerWithPort(
        Transfer $transfer,
        AccountTransferPort $port,
        ?callable $onSaveTransfer = null,
    ): ReverseTransferHandler {
        $transferRepo = $this->createStub(TransferRepositoryInterface::class);
        $transferRepo->method('getByIdForUpdate')->willReturn($transfer);
        $transferRepo->method('save')->willReturnCallback($onSaveTransfer ?? static function (): void {});

        $txManager = new class implements TransactionManagerInterface {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }
        };

        return new ReverseTransferHandler(
            transfers:           $transferRepo,
            accountTransferPort: $port,
            transactionManager:  $txManager,
            logger:              new NullLogger(),
            outbox:              $this->createStub(OutboxRepositoryInterface::class),
            serializer:          new OutboxEventSerializer(),
            ledgerRecorder:      $this->createStub(LedgerEntryRecorderPort::class),
        );
    }

    /**
     * Build a handler where the caller supplies a custom outbox mock.
     *
     * @param list<object> $accountEvents
     */
    private function buildHandlerWithOutbox(
        Transfer             $transfer,
        array                $accountEvents = [],
        ?\App\Shared\Domain\Outbox\OutboxRepositoryInterface $outbox = null,
        ?callable            $onSaveTransfer = null,
    ): ReverseTransferHandler {
        $port = $this->createStub(AccountTransferPort::class);
        $port->method('executeDoubleEntry')->willReturn(
            new DoubleEntryResult(0, 0, array_map(
                fn ($e) => new TaggedEvent($e, $this->sourceId->toString()),
                $accountEvents,
            ))
        );

        $transferRepo = $this->createStub(TransferRepositoryInterface::class);
        $transferRepo->method('getByIdForUpdate')->willReturn($transfer);
        $transferRepo->method('save')->willReturnCallback($onSaveTransfer ?? static function (): void {});

        $txManager = new class implements TransactionManagerInterface {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }
        };

        return new ReverseTransferHandler(
            transfers:           $transferRepo,
            accountTransferPort: $port,
            transactionManager:  $txManager,
            logger:              new NullLogger(),
            outbox:              $outbox ?? $this->createStub(OutboxRepositoryInterface::class),
            serializer:          new OutboxEventSerializer(),
            ledgerRecorder:      $this->createStub(LedgerEntryRecorderPort::class),
        );
    }

    private function buildHandlerWithNoTransfer(): ReverseTransferHandler
    {
        $transferRepo = $this->createStub(TransferRepositoryInterface::class);
        $transferRepo->method('getByIdForUpdate')->willThrowException(
            new TransferNotFoundException('Transfer not found')
        );

        $port = $this->createStub(AccountTransferPort::class);

        $txManager = new class implements TransactionManagerInterface {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }
        };

        return new ReverseTransferHandler(
            transfers:           $transferRepo,
            accountTransferPort: $port,
            transactionManager:  $txManager,
            logger:              new NullLogger(),
            outbox:              $this->createStub(OutboxRepositoryInterface::class),
            serializer:          new OutboxEventSerializer(),
            ledgerRecorder:      $this->createStub(LedgerEntryRecorderPort::class),
        );
    }
}
