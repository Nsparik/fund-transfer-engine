<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Transfer\Application\Command\InitiateTransfer;

use App\Module\Account\Application\Service\AccountTransferService;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Ledger\Infrastructure\Persistence\DbalLedgerRepository;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferCommand;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferHandler;
use App\Module\Transfer\Domain\Model\TransferStatus;
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
 * TC-1 — Sequential Depletion
 *
 * Verifies that a second transfer from a fully drained account is rejected with
 * INSUFFICIENT_FUNDS and that the failure leaves no ledger entries behind.
 *
 * Scenario:
 *   1. Account A starts with 1 000 minor-units.
 *   2. Transfer T1: 1 000 from A → B  (drains A completely).  COMPLETED.
 *   3. Transfer T2: 1 from A → C  (A has 0 remaining).  Must FAIL.
 *
 * Assertions after T2:
 *   a) T1 is COMPLETED with 2 ledger entries (1 debit + 1 credit).
 *   b) T2 is FAILED with 0 ledger entries written (transaction rolled back).
 *   c) Account A balance = 0  (unchanged from T1 completion).
 *   d) Account B balance = 1 000  (unchanged from T1 completion).
 *   e) Account C balance = 0  (T2 never touched it).
 *   f) T2 failure_code = INSUFFICIENT_FUNDS.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class SequentialDepletionTest extends TestCase
{
    private Connection              $connection;
    private DbalAccountRepository   $accountRepo;
    private DbalTransferRepository  $transferRepo;
    private InitiateTransferHandler $handler;

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

    // ─────────────────────────────────────────────────────────────────────────

    public function testSecondTransferFailsWithInsufficientFundsAndWritesNoLedgerEntries(): void
    {
        // ── Arrange ──────────────────────────────────────────────────────────
        $accountA = $this->makeAccount(balanceMinorUnits: 1_000);  // source, fully drained by T1
        $accountB = $this->makeAccount(balanceMinorUnits: 0);       // T1 destination
        $accountC = $this->makeAccount(balanceMinorUnits: 0);       // T2 destination (never touched)

        // ── Act: T1 — should succeed and drain A completely ───────────────────
        $t1Dto = ($this->handler)(new InitiateTransferCommand(
            sourceAccountId:      $accountA->getId()->toString(),
            destinationAccountId: $accountB->getId()->toString(),
            amountMinorUnits:     1_000,
            currency:             'USD',
            description:          'T1 — drain account A',
        ));
        $this->trackTransfer($t1Dto->id);

        self::assertSame(TransferStatus::COMPLETED->value, $t1Dto->status, 'T1 must complete');

        // ── Act: T2 — must fail (A has 0 remaining) ───────────────────────────
        $t2Exception = null;
        $t2FailedId  = null;

        try {
            ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $accountA->getId()->toString(),
                destinationAccountId: $accountC->getId()->toString(),
                amountMinorUnits:     1,
                currency:             'USD',
                description:          'T2 — should fail: A is empty',
            ));
        } catch (AccountRuleViolationException $e) {
            $t2Exception = $e;

            // The handler saves a FAILED record even when the domain throws.
            $failedRow = $this->connection->fetchAssociative(
                "SELECT id, status, failure_code FROM transfers
                  WHERE source_account_id = ? AND destination_account_id = ?
                  ORDER BY created_at DESC LIMIT 1",
                [$accountA->getId()->toString(), $accountC->getId()->toString()],
            );

            if ($failedRow !== false) {
                $t2FailedId = (string) $failedRow['id'];
                $this->trackTransfer($t2FailedId);
            }
        }

        // ── Assert: T2 raised the expected domain exception ───────────────────
        self::assertNotNull($t2Exception, 'T2 must throw AccountRuleViolationException');
        self::assertSame(
            'INSUFFICIENT_FUNDS',
            $t2Exception->getDomainCode(),
            'Failure code must be INSUFFICIENT_FUNDS',
        );

        // ── Assert: T2 is recorded as FAILED with correct code ────────────────
        self::assertNotNull($t2FailedId, 'A FAILED transfer row must exist for T2');

        $t2Row = $this->connection->fetchAssociative(
            'SELECT status, failure_code FROM transfers WHERE id = ?',
            [$t2FailedId],
        );

        self::assertNotFalse($t2Row, 'T2 transfer row must be reloadable');
        self::assertSame('failed',               $t2Row['status'],       'T2 status must be "failed"');
        self::assertSame('INSUFFICIENT_FUNDS',   $t2Row['failure_code'], 'T2 failure_code must be INSUFFICIENT_FUNDS');

        // ── Assert: T2 produced NO ledger entries ─────────────────────────────
        $t2LedgerCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$t2FailedId],
        );

        self::assertSame(
            0,
            $t2LedgerCount,
            'A FAILED transfer must produce zero ledger entries',
        );

        // ── Assert: T1 has exactly 2 ledger entries ───────────────────────────
        $t1LedgerRows = $this->connection->fetchAllAssociative(
            'SELECT account_id, entry_type FROM ledger_entries WHERE transfer_id = ? ORDER BY entry_type',
            [$t1Dto->id],
        );

        self::assertCount(2, $t1LedgerRows, 'T1 must produce exactly 2 ledger entries');
        $t1EntryTypes = array_column($t1LedgerRows, 'entry_type');
        self::assertContains('debit',  $t1EntryTypes, 'T1 must have a debit entry on source account');
        self::assertContains('credit', $t1EntryTypes, 'T1 must have a credit entry on destination account');

        // ── Assert: account balances are correct ──────────────────────────────
        $reloadedA = $this->accountRepo->getById($accountA->getId());
        $reloadedB = $this->accountRepo->getById($accountB->getId());
        $reloadedC = $this->accountRepo->getById($accountC->getId());

        self::assertSame(0,     $reloadedA->getBalance()->getAmountMinorUnits(), 'A must be 0 (drained by T1)');
        self::assertSame(1_000, $reloadedB->getBalance()->getAmountMinorUnits(), 'B must be 1000 (credited by T1)');
        self::assertSame(0,     $reloadedC->getBalance()->getAmountMinorUnits(), 'C must be 0 (T2 never touched it)');
    }

    public function testLedgerTotalCountAfterSuccessAndFailure(): void
    {
        // Additional invariant: the total number of ledger rows across the
        // entire test scenario must equal exactly the rows from T1 (2 rows).
        // T2's rollback must not leave orphaned ledger rows.
        $accountA = $this->makeAccount(balanceMinorUnits: 500);
        $accountB = $this->makeAccount(balanceMinorUnits: 0);
        $accountC = $this->makeAccount(balanceMinorUnits: 0);

        // T1: success
        $t1Dto = ($this->handler)(new InitiateTransferCommand(
            sourceAccountId:      $accountA->getId()->toString(),
            destinationAccountId: $accountB->getId()->toString(),
            amountMinorUnits:     500,
            currency:             'USD',
        ));
        $this->trackTransfer($t1Dto->id);

        // T2: fail (insufficient funds)
        $t2FailedId = null;
        try {
            ($this->handler)(new InitiateTransferCommand(
                sourceAccountId:      $accountA->getId()->toString(),
                destinationAccountId: $accountC->getId()->toString(),
                amountMinorUnits:     1,
                currency:             'USD',
            ));
        } catch (AccountRuleViolationException) {
            $failedRow = $this->connection->fetchAssociative(
                "SELECT id FROM transfers
                  WHERE source_account_id = ? AND destination_account_id = ?
                  ORDER BY created_at DESC LIMIT 1",
                [$accountA->getId()->toString(), $accountC->getId()->toString()],
            );
            if ($failedRow !== false) {
                $t2FailedId = (string) $failedRow['id'];
                $this->trackTransfer($t2FailedId);
            }
        }

        self::assertNotNull($t2FailedId);

        // Count ALL ledger rows across both transfers.
        $placeholders = implode(',', array_fill(0, 2, '?'));
        $totalLedgerRows = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entries WHERE transfer_id IN ({$placeholders})",
            [$t1Dto->id, $t2FailedId],
        );

        self::assertSame(
            2,
            $totalLedgerRows,
            'Across T1 (success) + T2 (fail), exactly 2 ledger rows must exist (only from T1)',
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

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
