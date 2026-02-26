<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Transfer\Application\Command\ReverseTransfer;

use App\Module\Account\Application\Service\AccountTransferService;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Ledger\Infrastructure\Persistence\DbalLedgerRepository;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferCommand;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferHandler;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferCommand;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferHandler;
use App\Module\Transfer\Domain\Exception\InvalidTransferStateException;
use App\Module\Transfer\Domain\Model\TransferStatus;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Infrastructure\Persistence\DbalTransferRepository;
use App\Module\Transfer\Infrastructure\Transaction\DbalTransactionManager;
use App\Shared\Domain\Exception\AccountRuleViolationException;
use App\Shared\Infrastructure\Outbox\DbalOutboxRepository;
use App\Shared\Infrastructure\Outbox\OutboxEventSerializer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ReverseTransferHandler against the real MySQL container.
 *
 * Verifies the full reversal path:
 *   — Happy path: source credited, destination debited, transfer REVERSED atomically.
 *   — Reversal of non-COMPLETED transfer → 409 InvalidTransferStateException.
 *   — Insufficient funds at destination → 422 InsufficientFundsException.
 *   — Double reversal attempt → InvalidTransferStateException (row lock + state check).
 *   — reversedAt timestamp is persisted and reloadable.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class ReverseTransferHandlerTest extends TestCase
{
    private Connection              $connection;
    private DbalAccountRepository   $accountRepo;
    private DbalTransferRepository  $transferRepo;
    private InitiateTransferHandler $initiateHandler;
    private ReverseTransferHandler  $reverseHandler;

    /** @var list<string> */
    private array $accountIds  = [];
    /** @var list<string> */
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

        $this->initiateHandler = new InitiateTransferHandler(
            $this->transferRepo,
            $accountTransferPort,
            $txManager,
            new NullLogger(),
            $outbox,
            $serializer,
            $ledgerRecorder,
        );

        $this->reverseHandler = new ReverseTransferHandler(
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
        }
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function testSuccessfulReversalCreditsSourceDebitsDestination(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 10_000, destBalance: 5_000);

        // Step 1: perform a completed transfer of 3000
        $transferDto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     3_000,
            currency:             'USD',
        ));
        $this->transferIds[] = $transferDto->id;

        // Post-transfer balances: source = 7000, dest = 8000
        self::assertSame(TransferStatus::COMPLETED->value, $transferDto->status);

        // Step 2: reverse it
        $reversalDto = ($this->reverseHandler)(new ReverseTransferCommand($transferDto->id));
        // No separate tracking needed — same ID as transfer

        self::assertSame(TransferStatus::REVERSED->value, $reversalDto->status);
        self::assertNotNull($reversalDto->reversedAt);

        // Post-reversal: source = 10000 (restored), dest = 5000 (restored)
        $reloadedSource = $this->accountRepo->getById($source->getId());
        $reloadedDest   = $this->accountRepo->getById($dest->getId());

        self::assertSame(10_000, $reloadedSource->getBalance()->getAmountMinorUnits());
        self::assertSame(5_000,  $reloadedDest->getBalance()->getAmountMinorUnits());
    }

    public function testReversedAtTimestampIsPersistedAndReloadable(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 5_000, destBalance: 5_000);

        $transferDto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     1_000,
            currency:             'USD',
        ));
        $this->transferIds[] = $transferDto->id;

        $reversalDto = ($this->reverseHandler)(new ReverseTransferCommand($transferDto->id));

        // Reload from DB and verify reversed_at was persisted.
        $reloaded = $this->transferRepo->getById(TransferId::fromString($transferDto->id));

        self::assertSame(TransferStatus::REVERSED, $reloaded->getStatus());
        self::assertNotNull($reloaded->getReversedAt());
        self::assertNotNull($reversalDto->reversedAt);

        // Version: PENDING(0) → PROCESSING(1) → COMPLETED(2) → REVERSED(3)
        self::assertSame(3, $reloaded->getVersion());
    }

    // ── State-machine guard: only COMPLETED can be reversed ───────────────────

    public function testReversalOfPendingTransferThrowsInvalidState(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 5_000, destBalance: 0);

        // Insert a raw PENDING transfer (bypass the handler to keep state PENDING).
        $pendingId = (string) \Symfony\Component\Uid\UuidV7::generate();
        $this->connection->executeStatement(
            "INSERT INTO transfers
                (id, reference, source_account_id, destination_account_id,
                 amount_minor_units, currency, status, created_at, updated_at, version)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(6), NOW(6), 0)",
            [
                $pendingId,
                'TXN-20260224-' . strtoupper(substr(str_replace('-', '', $pendingId), -12)),
                $source->getId()->toString(),
                $dest->getId()->toString(),
                1_000,
                'USD',
                'pending',
            ]
        );
        $this->transferIds[] = $pendingId;

        $this->expectException(InvalidTransferStateException::class);

        ($this->reverseHandler)(new ReverseTransferCommand($pendingId));
    }

    // ── Insufficient funds at destination ─────────────────────────────────────

    public function testReversalFailsWhenDestinationHasInsufficientFunds(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 10_000, destBalance: 0);

        // Transfer 5000 to dest — dest now has 5000, source has 5000.
        $transferDto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     5_000,
            currency:             'USD',
        ));
        $this->transferIds[] = $transferDto->id;

        // Drain the destination manually so it can no longer cover the reversal debit.
        $reloadedDest = $this->accountRepo->getById($dest->getId());
        // Transfer all dest funds away via another transfer to a third account.
        $third = $this->makeAccount(0);
        $drainDto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $dest->getId()->toString(),
            destinationAccountId: $third->getId()->toString(),
            amountMinorUnits:     5_000,
            currency:             'USD',
        ));
        $this->transferIds[] = $drainDto->id;

        // Now dest has 0 — reversal should fail with AccountRuleViolationException (wrapping InsufficientFundsException).
        $this->expectException(AccountRuleViolationException::class);

        try {
            ($this->reverseHandler)(new ReverseTransferCommand($transferDto->id));
        } finally {
            // Transfer must remain COMPLETED (not REVERSED) after the failed reversal attempt.
            $reloaded = $this->transferRepo->getById(TransferId::fromString($transferDto->id));
            self::assertSame(TransferStatus::COMPLETED, $reloaded->getStatus());
        }
    }

    // ── Double-reversal guard ─────────────────────────────────────────────────

    public function testDoubleReversalThrowsInvalidTransferState(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 5_000, destBalance: 5_000);

        $transferDto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     1_000,
            currency:             'USD',
        ));
        $this->transferIds[] = $transferDto->id;

        // First reversal succeeds.
        ($this->reverseHandler)(new ReverseTransferCommand($transferDto->id));

        // Second reversal must throw.
        $this->expectException(InvalidTransferStateException::class);

        ($this->reverseHandler)(new ReverseTransferCommand($transferDto->id));
    }

    /**
     * TC-2 — Double Reversal: DB assertions
     *
     * Verifies that a second reversal attempt:
     *   a) Throws InvalidTransferStateException.
     *   b) Leaves the transfer status as REVERSED (not changed to any other state).
     *   c) Does NOT write additional ledger entries (stays at 4 rows: 2 from original
     *      transfer + 2 from the first successful reversal).
     *   d) Leaves account balances unchanged from post-first-reversal state.
     *
     * The FOR UPDATE lock on the transfer row in ReverseTransferHandler is the
     * primary concurrency guard.  The state-machine check (canTransitionTo) is the
     * domain-level guard.  Both are exercised here.
     */
    public function testDoubleReversalDoesNotCreateAdditionalLedgerEntriesOrChangeTransferState(): void
    {
        [$source, $dest] = $this->makePair(sourceBalance: 8_000, destBalance: 2_000);

        // Step 1: complete a transfer
        $transferDto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $source->getId()->toString(),
            destinationAccountId: $dest->getId()->toString(),
            amountMinorUnits:     2_000,
            currency:             'USD',
        ));
        $this->transferIds[] = $transferDto->id;

        // Post-transfer: source = 6000, dest = 4000; ledger has 2 rows.

        // Step 2: first reversal — must succeed
        $reversalDto = ($this->reverseHandler)(new ReverseTransferCommand($transferDto->id));
        self::assertSame(TransferStatus::REVERSED->value, $reversalDto->status, 'First reversal must succeed');

        // Post-reversal: source = 8000, dest = 2000; ledger has 4 rows.

        // Capture ledger row count and account balances after the first reversal.
        $ledgerCountAfterFirstReversal = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$transferDto->id],
        );
        self::assertSame(4, $ledgerCountAfterFirstReversal, 'Exactly 4 ledger rows after first reversal');

        $sourceBalanceAfterFirstReversal = $this->accountRepo->getById($source->getId())
            ->getBalance()->getAmountMinorUnits();
        $destBalanceAfterFirstReversal   = $this->accountRepo->getById($dest->getId())
            ->getBalance()->getAmountMinorUnits();

        // Step 3: second reversal attempt — must throw
        $secondReversalException = null;
        try {
            ($this->reverseHandler)(new ReverseTransferCommand($transferDto->id));
        } catch (InvalidTransferStateException $e) {
            $secondReversalException = $e;
        }

        self::assertInstanceOf(
            InvalidTransferStateException::class,
            $secondReversalException,
            'Second reversal must throw InvalidTransferStateException',
        );

        // ── Assert: transfer state is still REVERSED ──────────────────────────
        $reloadedTransfer = $this->transferRepo->getById(TransferId::fromString($transferDto->id));
        self::assertSame(
            TransferStatus::REVERSED,
            $reloadedTransfer->getStatus(),
            'Transfer status must remain REVERSED after second reversal attempt',
        );

        // ── Assert: no new ledger entries were written ─────────────────────────
        $ledgerCountAfterSecondAttempt = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$transferDto->id],
        );
        self::assertSame(
            4,
            $ledgerCountAfterSecondAttempt,
            'Ledger entry count must remain 4 after failed second reversal (no new rows written)',
        );

        // ── Assert: account balances unchanged by the failed second attempt ────
        $sourceBalanceAfterSecondAttempt = $this->accountRepo->getById($source->getId())
            ->getBalance()->getAmountMinorUnits();
        $destBalanceAfterSecondAttempt   = $this->accountRepo->getById($dest->getId())
            ->getBalance()->getAmountMinorUnits();

        self::assertSame(
            $sourceBalanceAfterFirstReversal,
            $sourceBalanceAfterSecondAttempt,
            'Source account balance must not change after failed second reversal',
        );
        self::assertSame(
            $destBalanceAfterFirstReversal,
            $destBalanceAfterSecondAttempt,
            'Destination account balance must not change after failed second reversal',
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return array{0: Account, 1: Account} */
    private function makePair(int $sourceBalance, int $destBalance): array
    {
        return [
            $this->makeAccount($sourceBalance),
            $this->makeAccount($destBalance),
        ];
    }

    private function makeAccount(int $balance, string $currency = 'USD'): Account
    {
        $id      = AccountId::fromString((string) Uuid::v4());
        $account = Account::open($id, 'Test Owner', $currency, $balance);
        $this->accountRepo->save($account);
        $this->accountIds[] = $id->toString();

        return $account;
    }
}
