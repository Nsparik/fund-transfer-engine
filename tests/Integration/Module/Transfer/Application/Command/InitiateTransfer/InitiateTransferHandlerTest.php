<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Transfer\Application\Command\InitiateTransfer;

use App\Module\Account\Application\Service\AccountTransferService;
use App\Module\Account\Domain\Exception\AccountClosedException;
use App\Module\Account\Domain\Exception\AccountFrozenException;
use App\Module\Account\Domain\Exception\InsufficientFundsException;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Ledger\Infrastructure\Persistence\DbalLedgerRepository;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferCommand;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferHandler;
use App\Module\Transfer\Domain\Model\TransferStatus;
use App\Module\Transfer\Infrastructure\Persistence\DbalTransferRepository;
use App\Module\Transfer\Infrastructure\Transaction\DbalTransactionManager;
use App\Shared\Domain\Exception\AccountNotFoundForTransferException;
use App\Shared\Domain\Exception\AccountRuleViolationException;
use App\Shared\Infrastructure\Outbox\DbalOutboxRepository;
use App\Shared\Infrastructure\Outbox\OutboxEventSerializer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for InitiateTransferHandler against the real MySQL container.
 *
 * Verifies the full double-entry transfer path:
 *   — Happy path: source debited, destination credited, transfer COMPLETED in one tx.
 *   — Insufficient funds: transfer saved as FAILED, accounts unchanged.
 *   — Frozen source account: transfer saved as FAILED, accounts unchanged.
 *   — Frozen destination account: transfer saved as FAILED, accounts unchanged.
 *   — Missing account: AccountNotFoundException propagates, no transfer saved.
 *   — Currency mismatch: transfer saved as FAILED, accounts unchanged.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class InitiateTransferHandlerTest extends TestCase
{
    private Connection              $connection;
    private DbalAccountRepository   $accountRepo;
    private DbalTransferRepository  $transferRepo;
    private InitiateTransferHandler $handler;

    /** @var list<string> Account IDs to delete in tearDown */
    private array $accountIds  = [];
    /** @var list<string> Transfer IDs to delete in tearDown */
    private array $transferIds = [];

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection   = DriverManager::getConnection(['url' => $url]);
        $this->accountRepo  = new DbalAccountRepository($this->connection);
        $this->transferRepo = new DbalTransferRepository($this->connection);
        $txManager          = new DbalTransactionManager($this->connection);
        $accountTransferPort = new AccountTransferService($this->accountRepo);
        $outbox             = new DbalOutboxRepository($this->connection);
        $serializer         = new OutboxEventSerializer();
        $ledgerRecorder     = new DbalLedgerRepository($this->connection);

        $this->handler = new InitiateTransferHandler(
            $this->transferRepo,
            $accountTransferPort,
            $txManager,
            new NullLogger(),
            $outbox,
            $serializer,
            $ledgerRecorder,
        );
    }

    protected function tearDown(): void
    {
        if ($this->transferIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->transferIds), '?'));
            // Clean ledger entries, outbox events (by transfer_id), then transfers.
            $this->connection->executeStatement(
                "DELETE FROM ledger_entries WHERE transfer_id IN ({$placeholders})",
                $this->transferIds,
            );
            $this->connection->executeStatement(
                "DELETE FROM outbox_events WHERE aggregate_id IN ({$placeholders})",
                $this->transferIds,
            );
            $this->connection->executeStatement(
                "DELETE FROM transfers WHERE id IN ({$placeholders})",
                $this->transferIds,
            );
            $this->transferIds = [];
        }

        if ($this->accountIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->accountIds), '?'));
            // Also clean any outbox events written with account IDs as aggregate_id
            // (AccountDebited / AccountCredited events written to outbox).
            $this->connection->executeStatement(
                "DELETE FROM outbox_events WHERE aggregate_id IN ({$placeholders})",
                $this->accountIds,
            );
            $this->connection->executeStatement(
                "DELETE FROM accounts WHERE id IN ({$placeholders})",
                $this->accountIds,
            );
            $this->accountIds = [];
        }
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function testSuccessfulTransferDebitsSourceAndCreditsDestination(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 10_000, destBalance: 0);

        $dto = ($this->handler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     3_000,
            currency:             'USD',
            description:          'Test payment',
        ));

        $this->trackTransfer($dto->id);

        self::assertSame(TransferStatus::COMPLETED->value, $dto->status);
        self::assertSame(3_000, $dto->amountMinorUnits);
        self::assertSame('USD', $dto->currency);
        self::assertNotNull($dto->completedAt);
        self::assertNull($dto->failureCode);

        // Re-load from DB and assert final balances.
        $reloadedSource = $this->accountRepo->getById($source->getId());
        $reloadedDest   = $this->accountRepo->getById($dest->getId());

        self::assertSame(7_000, $reloadedSource->getBalance()->getAmountMinorUnits());
        self::assertSame(3_000, $reloadedDest->getBalance()->getAmountMinorUnits());

        // Debit increments source version by 1; credit increments dest version by 1.
        self::assertSame(1, $reloadedSource->getVersion());
        self::assertSame(1, $reloadedDest->getVersion());
    }

    public function testTransferIsPersistableAndReloadableAfterSuccess(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 5_000, destBalance: 2_000);

        $dto = ($this->handler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     1_000,
            currency:             'USD',
        ));

        $this->trackTransfer($dto->id);

        $transferId = \App\Module\Transfer\Domain\ValueObject\TransferId::fromString($dto->id);
        $reloaded   = $this->transferRepo->getById($transferId);

        self::assertSame(TransferStatus::COMPLETED, $reloaded->getStatus());
        self::assertSame(1_000, $reloaded->getAmount()->getAmountMinorUnits());
        self::assertSame('USD', $reloaded->getAmount()->getCurrency());
        self::assertSame(2, $reloaded->getVersion()); // PENDING→PROCESSING→COMPLETED
        self::assertNotNull($reloaded->getCompletedAt());
    }

    public function testHappyPathWritesTransferInitiatedAndTransferCompletedToOutbox(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 5_000, destBalance: 0);

        $dto = ($this->handler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     2_000,
            currency:             'USD',
        ));

        $this->trackTransfer($dto->id);

        // Two Transfer-level events must be atomically written to outbox_events
        // in the same transaction as the business operation.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_type FROM outbox_events WHERE aggregate_id = ? ORDER BY created_at ASC',
            [$dto->id],
        );

        $eventTypes = array_column($rows, 'event_type');

        self::assertCount(2, $eventTypes, 'Exactly TransferInitiated + TransferCompleted must be in the outbox');
        self::assertContains(\App\Module\Transfer\Domain\Event\TransferInitiated::class, $eventTypes);
        self::assertContains(\App\Module\Transfer\Domain\Event\TransferCompleted::class, $eventTypes);
    }

    public function testFailedTransferWritesTransferInitiatedAndTransferFailedToOutbox(): void
    {
        // Use insufficient funds as the canonical FAILED path: the handler saves a
        // PENDING transfer, emits TransferInitiated, then the debit fails, rolls the
        // accounts back, saves a FAILED transfer, and emits TransferFailed — all in
        // the same DB transaction. Both events must appear in outbox_events.
        [$source, $dest] = $this->makePair(sourceBalance: 100, destBalance: 0);

        $this->expectException(AccountRuleViolationException::class);

        try {
            ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $source->getId()->toString(),
                destinationAccountId: $dest->getId()->toString(),
                amountMinorUnits:     5_000, // more than source balance
                currency:             'USD',
            ));
        } catch (AccountRuleViolationException $e) {
            // Find the FAILED transfer written by the handler.
            $failedRow = $this->connection->fetchAssociative(
                "SELECT id FROM transfers
                  WHERE source_account_id = ? AND destination_account_id = ?
                  ORDER BY created_at DESC LIMIT 1",
                [$source->getId()->toString(), $dest->getId()->toString()],
            );

            self::assertNotFalse($failedRow, 'A FAILED transfer row must exist in DB');
            $this->trackTransfer((string) $failedRow['id']);

            $transferId = (string) $failedRow['id'];

            $rows = $this->connection->fetchAllAssociative(
                'SELECT event_type FROM outbox_events WHERE aggregate_id = ? ORDER BY created_at ASC',
                [$transferId],
            );

            $eventTypes = array_column($rows, 'event_type');

            self::assertCount(2, $eventTypes, 'Both TransferInitiated and TransferFailed must be in the outbox');
            self::assertContains(\App\Module\Transfer\Domain\Event\TransferInitiated::class, $eventTypes);
            self::assertContains(\App\Module\Transfer\Domain\Event\TransferFailed::class,    $eventTypes);

            throw $e;
        }
    }

    // ── Insufficient funds ────────────────────────────────────────────────────

    public function testInsufficientFundsFailsTransferAndLeavesAccountsUnchanged(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 500, destBalance: 200);

        $this->expectException(AccountRuleViolationException::class);

        try {
            $dto = ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $source->getId()->toString(),
                destinationAccountId: $dest->getId()->toString(),
                amountMinorUnits:     5_000, // more than source balance
                currency:             'USD',
            ));
            $this->trackTransfer($dto->id);
        } catch (AccountRuleViolationException $e) {
            self::assertSame('INSUFFICIENT_FUNDS', $e->getDomainCode());

            // Verify accounts are unchanged.
            $reloadedSource = $this->accountRepo->getById($source->getId());
            $reloadedDest   = $this->accountRepo->getById($dest->getId());

            self::assertSame(500, $reloadedSource->getBalance()->getAmountMinorUnits());
            self::assertSame(200, $reloadedDest->getBalance()->getAmountMinorUnits());

            // Find the FAILED transfer by querying directly (no ID in exception).
            $failedRow = $this->connection->fetchAssociative(
                "SELECT id, status, failure_code FROM transfers
                 WHERE source_account_id = ? AND destination_account_id = ?
                 ORDER BY created_at DESC LIMIT 1",
                [$source->getId()->toString(), $dest->getId()->toString()],
            );

            self::assertNotFalse($failedRow, 'A FAILED transfer row must be saved');
            $this->trackTransfer((string) $failedRow['id']);
            self::assertSame('failed', $failedRow['status']);
            self::assertSame('INSUFFICIENT_FUNDS', $failedRow['failure_code']);

            throw $e;
        }
    }

    // ── Frozen source account ─────────────────────────────────────────────────

    public function testFrozenSourceAccountFailsTransferWithCorrectCode(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 10_000, destBalance: 0);

        // Freeze the source account.
        $source->freeze();
        $this->accountRepo->save($source);

        $this->expectException(AccountRuleViolationException::class);

        try {
            $dto = ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $source->getId()->toString(),
                destinationAccountId: $dest->getId()->toString(),
                amountMinorUnits:     1_000,
                currency:             'USD',
            ));
            $this->trackTransfer($dto->id);
        } catch (AccountRuleViolationException $e) {
            self::assertSame('ACCOUNT_FROZEN', $e->getDomainCode());

            $failedRow = $this->connection->fetchAssociative(
                "SELECT id, status, failure_code FROM transfers
                 WHERE source_account_id = ? AND destination_account_id = ?
                 ORDER BY created_at DESC LIMIT 1",
                [$source->getId()->toString(), $dest->getId()->toString()],
            );

            self::assertNotFalse($failedRow, 'A FAILED transfer row must be saved');
            $this->trackTransfer((string) $failedRow['id']);
            self::assertSame('failed', $failedRow['status']);
            self::assertSame('ACCOUNT_FROZEN', $failedRow['failure_code']);

            // Source balance must be unchanged (frozen, not debited).
            $reloadedSource = $this->accountRepo->getById($source->getId());
            self::assertSame(10_000, $reloadedSource->getBalance()->getAmountMinorUnits());

            throw $e;
        }
    }

    // ── Frozen destination account ────────────────────────────────────────────

    public function testFrozenDestinationAccountFailsTransfer(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 10_000, destBalance: 0);

        // Freeze the destination account.
        $dest->freeze();
        $this->accountRepo->save($dest);

        $this->expectException(AccountRuleViolationException::class);

        try {
            $dto = ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $source->getId()->toString(),
                destinationAccountId: $dest->getId()->toString(),
                amountMinorUnits:     1_000,
                currency:             'USD',
            ));
            $this->trackTransfer($dto->id);
        } catch (AccountRuleViolationException $e) {
            self::assertSame('ACCOUNT_FROZEN', $e->getDomainCode());

            // Source balance must be unchanged (rollback protected it).
            $reloadedSource = $this->accountRepo->getById($source->getId());
            self::assertSame(10_000, $reloadedSource->getBalance()->getAmountMinorUnits());

            $failedRow = $this->connection->fetchAssociative(
                "SELECT id, status, failure_code FROM transfers
                 WHERE source_account_id = ? AND destination_account_id = ?
                 ORDER BY created_at DESC LIMIT 1",
                [$source->getId()->toString(), $dest->getId()->toString()],
            );

            self::assertNotFalse($failedRow);
            $this->trackTransfer((string) $failedRow['id']);
            self::assertSame('ACCOUNT_FROZEN', $failedRow['failure_code']);

            throw $e;
        }
    }

    // ── Missing account ───────────────────────────────────────────────────────

    public function testMissingSourceAccountThrowsWithoutSavingAnyTransfer(): void
    {
        $dest = $this->makeAccount(balanceMinorUnits: 0);

        $nonExistentId = (string) Uuid::v4();

        $this->expectException(AccountNotFoundForTransferException::class);

        try {
            ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $nonExistentId,
                destinationAccountId: $dest->getId()->toString(),
                amountMinorUnits:     1_000,
                currency:             'USD',
            ));
        } catch (AccountNotFoundForTransferException $e) {
            self::assertSame('ACCOUNT_NOT_FOUND', $e->getDomainCode());

            // No transfer should have been saved.
            $row = $this->connection->fetchAssociative(
                'SELECT id FROM transfers WHERE source_account_id = ? LIMIT 1',
                [$nonExistentId],
            );
            self::assertFalse($row, 'No transfer row must be saved when source account is missing');

            throw $e;
        }
    }

    public function testMissingDestinationAccountThrowsWithoutSavingAnyTransfer(): void
    {
        $source = $this->makeAccount(balanceMinorUnits: 5_000);

        $nonExistentId = (string) Uuid::v4();

        $this->expectException(AccountNotFoundForTransferException::class);

        try {
            ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $source->getId()->toString(),
                destinationAccountId: $nonExistentId,
                amountMinorUnits:     1_000,
                currency:             'USD',
            ));
        } catch (AccountNotFoundForTransferException $e) {
            self::assertSame('ACCOUNT_NOT_FOUND', $e->getDomainCode());

            $row = $this->connection->fetchAssociative(
                'SELECT id FROM transfers WHERE destination_account_id = ? LIMIT 1',
                [$nonExistentId],
            );
            self::assertFalse($row, 'No transfer row must be saved when destination account is missing');

            throw $e;
        }
    }

    // ── Deadlock-safe lock ordering ───────────────────────────────────────────

    /**
     * Transfer where source UUID > destination UUID exercises the reversed-lock
     * path (lock dest first, then source) — verifying both branches of the
     * strcmp() sort in the handler.
     */
    public function testTransferSucceedsWhenSourceUuidIsLexicographicallyHigherThanDest(): void
    {
        // Fix the UUIDs so we KNOW source > dest in string comparison.
        $lowerUuid  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; // will be dest → locked first
        $higherUuid = 'eeeeeeee-eeee-4eee-aeee-eeeeeeeeeeee'; // will be source → locked second

        $source = Account::open(AccountId::fromString($higherUuid), 'Source Owner', 'USD', 10_000);
        $dest   = Account::open(AccountId::fromString($lowerUuid),  'Dest Owner',   'USD', 0);
        $this->accountRepo->save($source);
        $this->accountRepo->save($dest);
        $this->accountIds[] = $higherUuid;
        $this->accountIds[] = $lowerUuid;

        $dto = ($this->handler)(new InitiateTransferCommand(
            sourceAccountId:      $higherUuid,
            destinationAccountId: $lowerUuid,
            amountMinorUnits:     4_000,
            currency:             'USD',
        ));
        $this->trackTransfer($dto->id);

        self::assertSame(TransferStatus::COMPLETED->value, $dto->status);

        $reloadedSource = $this->accountRepo->getById(AccountId::fromString($higherUuid));
        $reloadedDest   = $this->accountRepo->getById(AccountId::fromString($lowerUuid));

        self::assertSame(6_000, $reloadedSource->getBalance()->getAmountMinorUnits());
        self::assertSame(4_000, $reloadedDest->getBalance()->getAmountMinorUnits());
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    // ── Currency mismatch ─────────────────────────────────────────────────────

    public function testCurrencyMismatchFailsTransferAndLeavesAccountsUnchanged(): void
    {
        $source = $this->makeAccount(balanceMinorUnits: 10_000, currency: 'USD');
        $dest   = $this->makeAccount(balanceMinorUnits: 0,      currency: 'EUR');

        $this->expectException(AccountRuleViolationException::class);

        try {
            $dto = ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $source->getId()->toString(),
                destinationAccountId: $dest->getId()->toString(),
                amountMinorUnits:     1_000,
                currency:             'USD', // USD → EUR: mismatch on dest credit
            ));
            $this->trackTransfer($dto->id);
        } catch (AccountRuleViolationException $e) {
            self::assertSame('CURRENCY_MISMATCH', $e->getDomainCode());

            // Both account balances must be unchanged.
            $reloadedSource = $this->accountRepo->getById($source->getId());
            $reloadedDest   = $this->accountRepo->getById($dest->getId());

            self::assertSame(10_000, $reloadedSource->getBalance()->getAmountMinorUnits());
            self::assertSame(0, $reloadedDest->getBalance()->getAmountMinorUnits());

            // A FAILED transfer must have been recorded.
            $failedRow = $this->connection->fetchAssociative(
                "SELECT id, status, failure_code FROM transfers
                 WHERE source_account_id = ? AND destination_account_id = ?
                 ORDER BY created_at DESC LIMIT 1",
                [$source->getId()->toString(), $dest->getId()->toString()],
            );

            self::assertNotFalse($failedRow, 'A FAILED transfer row must be saved on currency mismatch');
            $this->trackTransfer((string) $failedRow['id']);
            self::assertSame('failed', $failedRow['status']);
            self::assertSame('CURRENCY_MISMATCH', $failedRow['failure_code']);

            throw $e;
        }
    }

    // ── Exact-balance transfer (drain entire balance) ─────────────────────────

    /**
     * Transferring precisely the source account's entire balance must succeed.
     * Verifies Balance::subtract() allows amount == balance (not strictly less).
     */
    public function testTransferOfEntireSourceBalanceSucceeds(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 5_000, destBalance: 1_000);

        $dto = ($this->handler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     5_000, // exactly all funds
            currency:             'USD',
        ));

        $this->trackTransfer($dto->id);

        self::assertSame(TransferStatus::COMPLETED->value, $dto->status);

        $reloadedSource = $this->accountRepo->getById($source->getId());
        $reloadedDest   = $this->accountRepo->getById($dest->getId());

        self::assertSame(0,     $reloadedSource->getBalance()->getAmountMinorUnits());
        self::assertSame(6_000, $reloadedDest->getBalance()->getAmountMinorUnits());
    }

    // ── Destination existing balance is accumulated, not replaced ─────────────

    /**
     * A non-zero destination balance must be increased by the credit, not overwritten.
     * Guards against an accidental SET balance = amount instead of SET balance = balance + amount.
     */
    public function testCreditAccumulatesOnExistingDestinationBalance(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 10_000, destBalance: 3_500);

        $dto = ($this->handler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     2_500,
            currency:             'USD',
        ));

        $this->trackTransfer($dto->id);

        $reloadedDest = $this->accountRepo->getById($dest->getId());
        self::assertSame(6_000, $reloadedDest->getBalance()->getAmountMinorUnits()); // 3500 + 2500
    }

    // ── Closed source account ─────────────────────────────────────────────────

    public function testClosedSourceAccountFailsTransferWithCorrectCode(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 10_000, destBalance: 0);

        // Force CLOSED status via reconstitute (bypasses the Account state machine for test setup).
        $closedSource = \App\Module\Account\Domain\Model\Account::reconstitute(
            id:        $source->getId(),
            ownerName: 'Closed Owner',
            currency:  'USD',
            balance:   new \App\Module\Account\Domain\ValueObject\Balance(10_000, 'USD'),
            status:    \App\Module\Account\Domain\Model\AccountStatus::CLOSED,
            createdAt: $source->getCreatedAt(),
            updatedAt: $source->getUpdatedAt(),
            version:   $source->getVersion(),
        );
        $this->accountRepo->save($closedSource);

        $this->expectException(AccountRuleViolationException::class);

        try {
            $dto = ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $source->getId()->toString(),
                destinationAccountId: $dest->getId()->toString(),
                amountMinorUnits:     1_000,
                currency:             'USD',
            ));
            $this->trackTransfer($dto->id);
        } catch (AccountRuleViolationException $e) {
            self::assertSame('ACCOUNT_CLOSED', $e->getDomainCode());

            $failedRow = $this->connection->fetchAssociative(
                "SELECT id, status, failure_code FROM transfers
                 WHERE source_account_id = ? AND destination_account_id = ?
                 ORDER BY created_at DESC LIMIT 1",
                [$source->getId()->toString(), $dest->getId()->toString()],
            );

            self::assertNotFalse($failedRow, 'A FAILED transfer row must be saved for closed account');
            $this->trackTransfer((string) $failedRow['id']);
            self::assertSame('failed', $failedRow['status']);
            self::assertSame('ACCOUNT_CLOSED', $failedRow['failure_code']);

            // Dest balance must be unchanged.
            $reloadedDest = $this->accountRepo->getById($dest->getId());
            self::assertSame(0, $reloadedDest->getBalance()->getAmountMinorUnits());

            throw $e;
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Create a pair of USD accounts and persist them.
     *
     * @return array{0: Account, 1: Account}
     */
    private function makePair(int $sourceBalance, int $destBalance): array
    {
        $source = $this->makeAccount(balanceMinorUnits: $sourceBalance);
        $dest   = $this->makeAccount(balanceMinorUnits: $destBalance);

        return [$source, $dest];
    }

    private function makeAccount(int $balanceMinorUnits = 0, string $currency = 'USD'): Account
    {
        $id      = AccountId::fromString((string) Uuid::v4());
        $account = Account::open($id, 'Test Owner', $currency, $balanceMinorUnits);
        $this->accountRepo->save($account);
        $this->accountIds[] = $id->toString();

        return $account;
    }

    private function trackTransfer(string $transferId): void
    {
        $this->transferIds[] = $transferId;
    }
}
