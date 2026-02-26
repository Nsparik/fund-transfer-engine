<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Ledger;

use App\Module\Account\Application\Command\CreateAccount\CreateAccountCommand;
use App\Module\Account\Application\Command\CreateAccount\CreateAccountHandler;
use App\Module\Account\Application\Service\AccountTransferService;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Ledger\Domain\Repository\LedgerRepositoryInterface;
use App\Module\Ledger\Infrastructure\Persistence\DbalLedgerRepository;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferCommand;
use App\Module\Transfer\Application\Command\InitiateTransfer\InitiateTransferHandler;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferCommand;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferHandler;
use App\Module\Transfer\Infrastructure\Persistence\DbalTransferRepository;
use App\Module\Transfer\Infrastructure\Transaction\DbalTransactionManager;
use App\Shared\Infrastructure\Outbox\DbalOutboxRepository;
use App\Shared\Infrastructure\Outbox\OutboxEventSerializer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

/**
 * Ledger Integrity Certification — Integration Test Suite.
 *
 * Proves the following invariants against a live MySQL database:
 *
 *   INV-3  Sum of signed amounts per transfer_id equals zero.
 *          Verifies mathematical conservation of money (no creation/destruction).
 *
 *   INV-4  Ledger rows are never updated or deleted.
 *          Proved structurally: LedgerRepositoryInterface and DbalLedgerRepository
 *          expose zero methods with "update" or "delete" in their names.
 *
 *   INV-5  Account balance is fully derivable from ledger entries alone.
 *          SUM(CREDITs) - SUM(DEBITs) == live account.balance_minor_units.
 *
 *   INV-8  Reversal appends new rows — never mutates originals.
 *          Original transfer entries are byte-identical before and after reversal.
 *          Net balance is fully restored after transfer + reversal.
 *
 *   INV-9  Bootstrap entry is idempotent under retry.
 *          INSERT IGNORE discards duplicate bootstrap writes without corruption.
 *
 *   CRASH  MySQL ACID guarantees: a rolled-back transaction leaves no
 *          partial ledger entries in the database.
 *
 *   CHECK  DB-level CHECK(amount_minor_units > 0) rejects zero-amount inserts
 *          independently of the PHP domain guard (defence-in-depth).
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class LedgerIntegrityTest extends TestCase
{
    private Connection              $connection;
    private DbalAccountRepository   $accountRepo;
    private DbalLedgerRepository    $ledgerRepo;
    private InitiateTransferHandler $initiateHandler;
    private ReverseTransferHandler  $reverseHandler;
    private CreateAccountHandler    $createAccountHandler;

    /** @var list<string> Account IDs tracked for tearDown cleanup */
    private array $accountIds  = [];
    /** @var list<string> Transfer IDs tracked for tearDown cleanup */
    private array $transferIds = [];

    // ── Setup / Teardown ─────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection  = DriverManager::getConnection(['url' => $url]);
        $this->accountRepo = new DbalAccountRepository($this->connection);
        $this->ledgerRepo  = new DbalLedgerRepository($this->connection);

        $txManager           = new DbalTransactionManager($this->connection);
        $accountTransferPort = new AccountTransferService($this->accountRepo);
        $outbox              = new DbalOutboxRepository($this->connection);
        $serializer          = new OutboxEventSerializer();
        $dispatcher          = new EventDispatcher();

        $transferRepo = new DbalTransferRepository($this->connection);

        $this->initiateHandler = new InitiateTransferHandler(
            $transferRepo,
            $accountTransferPort,
            $txManager,
            new NullLogger(),
            $outbox,
            $serializer,
            $this->ledgerRepo,
        );

        $this->reverseHandler = new ReverseTransferHandler(
            $transferRepo,
            $accountTransferPort,
            $txManager,
            new NullLogger(),
            $outbox,
            $serializer,
            $this->ledgerRepo,
        );

        $this->createAccountHandler = new CreateAccountHandler(
            $this->accountRepo,
            $txManager,
            $dispatcher,
            new NullLogger(),
            $outbox,
            $serializer,
            $this->ledgerRepo,
        );
    }

    protected function tearDown(): void
    {
        if ($this->accountIds !== []) {
            // Delete ledger_entries FIRST — fk_ledger_entries_account is RESTRICT,
            // so the accounts DELETE will fail if any ledger rows still reference them.
            // This covers bootstrap entries AND transfer entries in one sweep.
            $ph = implode(',', array_fill(0, count($this->accountIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM ledger_entries WHERE account_id IN ({$ph})",
                $this->accountIds,
            );
        }

        if ($this->transferIds !== []) {
            $ph = implode(',', array_fill(0, count($this->transferIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM outbox_events WHERE aggregate_id IN ({$ph})",
                $this->transferIds,
            );
            $this->connection->executeStatement(
                "DELETE FROM transfers WHERE id IN ({$ph})",
                $this->transferIds,
            );
            $this->transferIds = [];
        }

        if ($this->accountIds !== []) {
            $ph = implode(',', array_fill(0, count($this->accountIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM outbox_events WHERE aggregate_id IN ({$ph})",
                $this->accountIds,
            );
            $this->connection->executeStatement(
                "DELETE FROM accounts WHERE id IN ({$ph})",
                $this->accountIds,
            );
            $this->accountIds = [];
        }
    }

    // ── INV-3: Signed sum per transfer equals zero ────────────────────────────

    /**
     * Invariant: INV-3
     *
     * For any completed transfer, the DEBIT amount and CREDIT amount in
     * ledger_entries are identical.  When signed (CREDIT = +, DEBIT = −)
     * they sum to exactly zero.
     *
     * This is the mathematical core of double-entry bookkeeping: money is
     * conserved — neither created nor destroyed by a transfer.
     */
    public function testSignedAmountSumEqualsZeroPerTransfer(): void
    {
        [$src, $dst] = $this->makePair(10_000, 0);

        $dto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     4_000,
            currency:             'USD',
        ));
        $this->transferIds[] = $dto->id;

        $rows = $this->connection->fetchAllAssociative(
            "SELECT entry_type, amount_minor_units
             FROM ledger_entries
             WHERE transfer_id = ? AND transfer_type = 'transfer'",
            [$dto->id],
        );

        self::assertCount(2, $rows, 'Exactly two entries (DEBIT + CREDIT) per transfer');

        // CREDIT contributes +amount; DEBIT contributes −amount
        $signedSum = 0;
        foreach ($rows as $row) {
            $signedSum += $row['entry_type'] === 'credit'
                ? (int) $row['amount_minor_units']
                : -(int) $row['amount_minor_units'];
        }

        self::assertSame(
            0,
            $signedSum,
            'CREDIT(+amount) + DEBIT(−amount) must equal zero — conservation of money',
        );
    }

    // ── INV-4: LedgerRepositoryInterface exposes no mutation methods ──────────

    /**
     * Invariant: INV-4
     *
     * Proves structurally that neither LedgerRepositoryInterface nor its sole
     * production implementation (DbalLedgerRepository) declares any public
     * method whose name begins with "update" or "delete".
     *
     * The interface contract ITSELF enforces append-only semantics — no caller
     * can mutate existing ledger rows because there is simply no method to call.
     */
    public function testLedgerRepositoryExposesNoMutationMethods(): void
    {
        foreach ([LedgerRepositoryInterface::class, DbalLedgerRepository::class] as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $name = strtolower($method->getName());
                self::assertFalse(
                    str_starts_with($name, 'update'),
                    sprintf(
                        '%s must not expose an "update" method (found: %s)',
                        $class,
                        $method->getName(),
                    ),
                );
                self::assertFalse(
                    str_starts_with($name, 'delete'),
                    sprintf(
                        '%s must not expose a "delete" method (found: %s)',
                        $class,
                        $method->getName(),
                    ),
                );
            }
        }
    }

    // ── INV-5: Account balance derivable from ledger alone ───────────────────

    /**
     * Invariant: INV-5 / Scenario 7
     *
     * Creates an account via CreateAccountHandler (producing a bootstrap CREDIT
     * ledger entry), then performs two transfers away from it.  Then computes:
     *
     *   ledger_derived = SUM(credit amounts) − SUM(debit amounts) for the account
     *
     * and asserts it equals the live account.balance_minor_units value.
     *
     * This proves the ledger is a complete financial history from which the
     * current balance can always be reconstructed independently of the accounts
     * table — a prerequisite for any reconciliation or audit trail.
     */
    public function testAccountBalanceDerivableFromLedgerAlone(): void
    {
        $srcId = (string) Uuid::v4();
        $dstId = (string) Uuid::v4();
        $this->accountIds[] = $srcId;
        $this->accountIds[] = $dstId;

        ($this->createAccountHandler)(new CreateAccountCommand($srcId, 'Ledger Source', 'USD', 10_000));
        ($this->createAccountHandler)(new CreateAccountCommand($dstId, 'Ledger Dest',   'USD', 0));

        // Transfer 3 000 from src → dst
        $t1 = ($this->initiateHandler)(new InitiateTransferCommand($srcId, $dstId, 3_000, 'USD'));
        $this->transferIds[] = $t1->id;

        // Transfer another 1 500 from src → dst
        $t2 = ($this->initiateHandler)(new InitiateTransferCommand($srcId, $dstId, 1_500, 'USD'));
        $this->transferIds[] = $t2->id;

        // Expected source balance: 10 000 − 3 000 − 1 500 = 5 500
        $expectedBalance = 5_500;

        // Derive balance purely from ledger entries (all entry types including bootstrap)
        $ledgerRow = $this->connection->fetchAssociative(
            "SELECT
                SUM(CASE WHEN entry_type = 'credit' THEN amount_minor_units ELSE 0 END)
                    - SUM(CASE WHEN entry_type = 'debit'  THEN amount_minor_units ELSE 0 END)
                    AS derived_balance
             FROM ledger_entries
             WHERE account_id = ?",
            [$srcId],
        );

        $ledgerDerived = (int) $ledgerRow['derived_balance'];

        // Read live balance from the accounts table
        $account     = $this->accountRepo->getById(AccountId::fromString($srcId));
        $liveBalance = $account->getBalance()->getAmountMinorUnits();

        self::assertSame($expectedBalance, $liveBalance, 'Live account balance must equal expected');
        self::assertSame(
            $liveBalance,
            $ledgerDerived,
            'Ledger-derived balance (SUM credits − SUM debits) must equal live account balance',
        );
    }

    // ── INV-8a: Reversal appends — never mutates originals ───────────────────

    /**
     * Invariant: INV-8 / Scenario 5
     *
     * Snapshots the two original ledger rows (id, amounts, balance_after) before
     * triggering a reversal.  After reversal, re-reads those same two rows and
     * asserts they are byte-identical to the pre-reversal snapshot.
     *
     * The reversal must have written two NEW rows (transfer_type = 'reversal')
     * without touching the originals.  Total entry count for the transfer_id
     * must be exactly four.
     */
    public function testReversalAppendsNewRowsWithoutMutatingOriginals(): void
    {
        [$src, $dst] = $this->makePair(10_000, 0);

        $dto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     4_500,
            currency:             'USD',
        ));
        $this->transferIds[] = $dto->id;

        // Capture original rows BEFORE reversal (order by entry_type for determinism)
        $before = $this->connection->fetchAllAssociative(
            "SELECT id, entry_type, transfer_type, amount_minor_units, balance_after_minor_units
             FROM ledger_entries
             WHERE transfer_id = ? AND transfer_type = 'transfer'
             ORDER BY entry_type",
            [$dto->id],
        );

        self::assertCount(2, $before, 'Two transfer-type entries must exist before reversal');

        // Execute the reversal
        ($this->reverseHandler)(new ReverseTransferCommand($dto->id));

        // Re-read the EXACT SAME rows after reversal
        $after = $this->connection->fetchAllAssociative(
            "SELECT id, entry_type, transfer_type, amount_minor_units, balance_after_minor_units
             FROM ledger_entries
             WHERE transfer_id = ? AND transfer_type = 'transfer'
             ORDER BY entry_type",
            [$dto->id],
        );

        self::assertSame(
            $before,
            $after,
            'Original ledger entries must be byte-identical after reversal — no mutation allowed',
        );

        // Exactly 2 reversal rows must have been appended
        $reversalCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entries
             WHERE transfer_id = ? AND transfer_type = 'reversal'",
            [$dto->id],
        );

        self::assertSame(2, $reversalCount, 'Reversal must append exactly 2 new rows');
    }

    // ── INV-8b: Net balance restored after complete reversal ─────────────────

    /**
     * Invariant: INV-8 / Scenario 5
     *
     * After transfer + full reversal, both the source and destination account
     * balances must be exactly restored to their pre-transfer values.
     *
     * This proves that the double-entry reversal is mathematically exact:
     * the DEBIT → CREDIT swap on both sides nets out perfectly.
     */
    public function testNetBalanceRestoredAfterTransferAndReversal(): void
    {
        $srcInitial = 10_000;
        $dstInitial = 5_000;
        [$src, $dst] = $this->makePair($srcInitial, $dstInitial);

        $dto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     3_000,
            currency:             'USD',
        ));
        $this->transferIds[] = $dto->id;

        ($this->reverseHandler)(new ReverseTransferCommand($dto->id));

        $reloadedSrc = $this->accountRepo->getById($src->getId());
        $reloadedDst = $this->accountRepo->getById($dst->getId());

        self::assertSame(
            $srcInitial,
            $reloadedSrc->getBalance()->getAmountMinorUnits(),
            'Source balance must be fully restored after reversal',
        );
        self::assertSame(
            $dstInitial,
            $reloadedDst->getBalance()->getAmountMinorUnits(),
            'Destination balance must be fully restored after reversal',
        );
    }

    // ── CRASH: Transaction rollback leaves no partial ledger entries ──────────

    /**
     * Scenario 8: Crash safety
     *
     * Manually begins a DBAL transaction, writes a DEBIT entry directly into
     * ledger_entries, then forces a rollback before commit.
     *
     * Verifies that zero rows exist for that entry ID after rollback.
     *
     * Proves MySQL ACID guarantees protect the ledger against partial writes
     * caused by process crashes, network failures, or uncaught exceptions — the
     * exact durability contract that DbalTransactionManager::transactional()
     * relies upon for financial atomicity.
     */
    public function testTransactionRollbackLeavesNoPartialLedgerEntry(): void
    {
        // Need real accounts to satisfy FK constraint on account_id
        $account      = $this->makeAccount(5_000);
        $counterparty = $this->makeAccount(0);

        $entryId = (string) Uuid::v4();

        try {
            $this->connection->beginTransaction();

            // Write the entry INSIDE the open transaction
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT IGNORE INTO ledger_entries
                    (id, account_id, counterparty_account_id, transfer_id,
                     entry_type, transfer_type, amount_minor_units, currency,
                     balance_after_minor_units, occurred_at, created_at)
                VALUES (?, ?, ?, ?, 'debit', 'transfer', 1000, 'USD', 4000, NOW(6), NOW(6))
                SQL,
                [
                    $entryId,
                    $account->getId()->toString(),
                    $counterparty->getId()->toString(),
                    (string) Uuid::v4(), // synthetic transfer_id — no FK on this column
                ],
            );

            // Simulate crash / exception AFTER write but BEFORE commit
            throw new \RuntimeException('Simulated application crash mid-transaction');
        } catch (\RuntimeException) {
            $this->connection->rollBack();
        }

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE id = ?',
            [$entryId],
        );

        self::assertSame(
            0,
            $count,
            'A rolled-back transaction must leave zero ledger entries in the database',
        );
    }

    // ── INV-9: Bootstrap entry is idempotent ─────────────────────────────────

    /**
     * Invariant: INV-9 / Scenario 9
     *
     * CreateAccountHandler writes a bootstrap CREDIT using the fixed
     * SYSTEM_BOOTSTRAP_TRANSFER_ID ('00000000-0000-7000-8000-000000000001').
     * The UNIQUE constraint on (account_id, transfer_id, entry_type) combined
     * with INSERT IGNORE guarantees that a duplicate write is silently discarded.
     *
     * This test simulates a retry of the bootstrap write (e.g. from a handler
     * retry after a network timeout) and verifies exactly one entry persists.
     */
    public function testBootstrapEntryIsIdempotentOnRetry(): void
    {
        $accountId = (string) Uuid::v4();
        $this->accountIds[] = $accountId;

        // First write via CreateAccountHandler — this produces 1 bootstrap CREDIT
        ($this->createAccountHandler)(new CreateAccountCommand($accountId, 'Retry User', 'USD', 7_500));

        // Simulate a retry: directly call recordBootstrapCreditEntry() with the
        // same accountId and the fixed SYSTEM_BOOTSTRAP_TRANSFER_ID.
        // INSERT IGNORE must silently discard this duplicate.
        $this->ledgerRepo->recordBootstrapCreditEntry(
            accountId:            $accountId,
            systemCounterpartyId: '00000000-0000-7000-8000-000000000000',
            transferId:           '00000000-0000-7000-8000-000000000001', // SYSTEM_BOOTSTRAP_TRANSFER_ID
            amountMinorUnits:     7_500,
            currency:             'USD',
            occurredAt:           new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entries
             WHERE account_id = ? AND transfer_type = 'bootstrap'",
            [$accountId],
        );

        self::assertSame(
            1,
            $count,
            'INSERT IGNORE must ensure exactly one bootstrap entry even after repeated writes',
        );
    }

    // ── CHECK: DB-level constraint blocks zero-amount inserts ─────────────────

    /**
     * Migration: Version20260226000002 (CHECK constraint defence-in-depth)
     *
     * Attempts a raw SQL INSERT with amount_minor_units = 0 into ledger_entries.
     * The DB-level CHECK constraint chk_ledger_entries_amount_positive must
     * reject this insert with a SQLSTATE[HY000] or SQLSTATE[23000] exception,
     * independently of the PHP domain guard LedgerEntry::assertPositiveAmount().
     *
     * This proves the database enforces financial correctness even if the
     * application layer is bypassed (e.g., direct SQL, migration scripts, bugs).
     */
    public function testCheckConstraintBlocksZeroAmountInsert(): void
    {
        $account      = $this->makeAccount(5_000);
        $counterparty = $this->makeAccount(0);

        $this->expectException(\Doctrine\DBAL\Exception::class);

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO ledger_entries
                (id, account_id, counterparty_account_id, transfer_id,
                 entry_type, transfer_type, amount_minor_units, currency,
                 balance_after_minor_units, occurred_at, created_at)
            VALUES (?, ?, ?, ?, 'debit', 'transfer', 0, 'USD', 5000, NOW(6), NOW(6))
            SQL,
            [
                (string) Uuid::v4(),
                $account->getId()->toString(),
                $counterparty->getId()->toString(),
                (string) Uuid::v4(),
            ],
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @return array{0: Account, 1: Account}
     */
    private function makePair(int $srcBalance, int $dstBalance): array
    {
        return [
            $this->makeAccount($srcBalance),
            $this->makeAccount($dstBalance),
        ];
    }

    /**
     * Persist an Account directly, bypassing CreateAccountHandler.
     * No bootstrap ledger entry is written — suitable for tests that only
     * need a valid FK-compatible account and do not require ledger traceability
     * from account creation.
     */
    private function makeAccount(int $balance = 0, string $currency = 'USD'): Account
    {
        $id      = AccountId::fromString((string) Uuid::v4());
        $account = Account::open($id, 'Test Owner', $currency, $balance);
        $this->accountRepo->save($account);
        $this->accountIds[] = $id->toString();

        return $account;
    }
}
