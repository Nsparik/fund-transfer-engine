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
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferCommand;
use App\Module\Transfer\Application\Command\ReverseTransfer\ReverseTransferHandler;
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
 * Integration tests: ledger entry creation as a side-effect of the transfer handlers.
 *
 * Verifies the ledger integration contract:
 *   - A COMPLETED transfer writes exactly 2 ledger entries (1 DEBIT + 1 CREDIT).
 *   - The balance_after values stored in those entries are correct.
 *   - A FAILED transfer writes 0 ledger entries (transaction rollback).
 *   - A REVERSAL appends exactly 2 more entries (total = 4).
 *   - Reversal entries carry transfer_type = 'reversal'.
 *   - The debit entry's balance_after matches the source account's balance post-transfer.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class LedgerEntriesOnTransferTest extends TestCase
{
    private Connection              $connection;
    private DbalAccountRepository   $accountRepo;
    private DbalTransferRepository  $transferRepo;
    private DbalLedgerRepository    $ledgerRepo;
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
        $this->ledgerRepo   = new DbalLedgerRepository($this->connection);

        $txManager          = new DbalTransactionManager($this->connection);
        $accountTransferPort = new AccountTransferService($this->accountRepo);
        $outbox             = new DbalOutboxRepository($this->connection);
        $serializer         = new OutboxEventSerializer();

        $this->initiateHandler = new InitiateTransferHandler(
            $this->transferRepo,
            $accountTransferPort,
            $txManager,
            new NullLogger(),
            $outbox,
            $serializer,
            $this->ledgerRepo,
        );

        $this->reverseHandler = new ReverseTransferHandler(
            $this->transferRepo,
            $accountTransferPort,
            $txManager,
            new NullLogger(),
            $outbox,
            $serializer,
            $this->ledgerRepo,
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

    // ── Happy path ────────────────────────────────────────────────────────────

    public function testCompletedTransferCreatesExactlyTwoLedgerEntries(): void
    {
        [$src, $dst] = $this->makePair(10_000, 0);

        $dto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     1_000,
            currency:             'USD',
        ));
        $this->trackTransfer($dto->id);

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$dto->id],
        );

        self::assertSame(2, $count, 'Exactly one DEBIT + one CREDIT must be created per transfer');
    }

    public function testDebitEntryHasCorrectBalanceAfterForSource(): void
    {
        [$src, $dst] = $this->makePair(10_000, 0);

        $dto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     1_000,
            currency:             'USD',
        ));
        $this->trackTransfer($dto->id);

        $row = $this->connection->fetchAssociative(
            "SELECT balance_after_minor_units FROM ledger_entries
             WHERE transfer_id = ? AND entry_type = 'debit'",
            [$dto->id],
        );

        self::assertIsArray($row);
        // 10 000 - 1 000 = 9 000
        self::assertSame(9_000, (int) $row['balance_after_minor_units']);
    }

    public function testCreditEntryHasCorrectBalanceAfterForDestination(): void
    {
        [$src, $dst] = $this->makePair(10_000, 5_000);

        $dto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     1_000,
            currency:             'USD',
        ));
        $this->trackTransfer($dto->id);

        $row = $this->connection->fetchAssociative(
            "SELECT balance_after_minor_units FROM ledger_entries
             WHERE transfer_id = ? AND entry_type = 'credit'",
            [$dto->id],
        );

        self::assertIsArray($row);
        // 5 000 + 1 000 = 6 000
        self::assertSame(6_000, (int) $row['balance_after_minor_units']);
    }

    // ── Failed transfer ───────────────────────────────────────────────────────

    public function testFailedTransferCreatesNoLedgerEntries(): void
    {
        [$src, $dst] = $this->makePair(100, 0);

        $this->expectException(AccountRuleViolationException::class);

        try {
            ($this->initiateHandler)(new InitiateTransferCommand(
                sourceAccountId:      $src->getId()->toString(),
                destinationAccountId: $dst->getId()->toString(),
                amountMinorUnits:     5_000, // exceeds source balance → FAILED
                currency:             'USD',
            ));
        } catch (AccountRuleViolationException $e) {
            // Find and track the FAILED transfer so tearDown can clean it up
            $failedRow = $this->connection->fetchAssociative(
                "SELECT id FROM transfers
                 WHERE source_account_id = ? AND destination_account_id = ?
                 ORDER BY created_at DESC LIMIT 1",
                [$src->getId()->toString(), $dst->getId()->toString()],
            );
            if ($failedRow !== false) {
                $this->trackTransfer((string) $failedRow['id']);
            }

            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ledger_entries WHERE account_id = ?',
                [$src->getId()->toString()],
            );

            self::assertSame(0, $count, 'A failed transfer must not produce any ledger entries');

            throw $e;
        }
    }

    // ── Reversal ──────────────────────────────────────────────────────────────

    public function testReversalCreatesTwoAdditionalLedgerEntries(): void
    {
        [$src, $dst] = $this->makePair(10_000, 0);

        $transfer = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     2_000,
            currency:             'USD',
        ));
        $this->trackTransfer($transfer->id);

        ($this->reverseHandler)(new ReverseTransferCommand($transfer->id));

        // Both the original transfer AND the reversal share the same transfer_id
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE transfer_id = ?',
            [$transfer->id],
        );

        self::assertSame(4, $count, 'Original (2) + reversal (2) = 4 ledger entries for the same transfer_id');
    }

    public function testReversalEntriesHaveTransferTypeReversal(): void
    {
        [$src, $dst] = $this->makePair(10_000, 0);

        $transfer = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     3_000,
            currency:             'USD',
        ));
        $this->trackTransfer($transfer->id);

        ($this->reverseHandler)(new ReverseTransferCommand($transfer->id));

        $reversalRows = $this->connection->fetchAllAssociative(
            "SELECT entry_type FROM ledger_entries
             WHERE transfer_id = ? AND transfer_type = 'reversal'",
            [$transfer->id],
        );

        self::assertCount(2, $reversalRows, 'Reversal must create exactly 2 entries with transfer_type=reversal');

        $types = array_column($reversalRows, 'entry_type');
        sort($types);
        self::assertSame(['credit', 'debit'], $types, 'Reversal entries must be one debit and one credit');
    }

    public function testBalanceAfterInLedgerMatchesAccountBalanceAfterTransfer(): void
    {
        [$src, $dst] = $this->makePair(8_000, 0);

        $dto = ($this->initiateHandler)(new InitiateTransferCommand(
            sourceAccountId:      $src->getId()->toString(),
            destinationAccountId: $dst->getId()->toString(),
            amountMinorUnits:     3_000,
            currency:             'USD',
        ));
        $this->trackTransfer($dto->id);

        // Balance stored in ledger entry must match the live account balance
        $ledgerRow = $this->connection->fetchAssociative(
            "SELECT balance_after_minor_units FROM ledger_entries
             WHERE transfer_id = ? AND account_id = ?",
            [$dto->id, $src->getId()->toString()],
        );

        $reloadedSrc = $this->accountRepo->getById($src->getId());

        self::assertIsArray($ledgerRow);
        self::assertSame(
            $reloadedSrc->getBalance()->getAmountMinorUnits(),
            (int) $ledgerRow['balance_after_minor_units'],
            'Ledger debit balance_after must equal account balance after transfer',
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

    private function makeAccount(int $balance = 0, string $currency = 'USD'): Account
    {
        $id      = AccountId::fromString((string) Uuid::v4());
        $account = Account::open($id, 'Test Owner', $currency, $balance);
        $this->accountRepo->save($account);
        $this->accountIds[] = $id->toString();

        return $account;
    }

    private function trackTransfer(string $id): void
    {
        $this->transferIds[] = $id;
    }
}
