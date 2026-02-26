<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Transfer\Infrastructure\Persistence;

use App\Module\Transfer\Domain\Exception\TransferNotFoundException;
use App\Module\Transfer\Domain\Model\Transfer;
use App\Module\Transfer\Domain\Model\TransferStatus;
use App\Module\Transfer\Domain\ValueObject\AccountId;
use App\Module\Transfer\Domain\ValueObject\Money;
use App\Module\Transfer\Domain\ValueObject\TransferId;
use App\Module\Transfer\Infrastructure\Persistence\DbalTransferRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DbalTransferRepository against the real MySQL container.
 *
 * Builds a raw DBAL Connection from DATABASE_URL (populated by bootEnv()
 * in bootstrap.php) — no Symfony kernel required.
 * Each test inserts its own rows and cleans them up in tearDown(), so they
 * are safe to run repeatedly against the shared `fund_transfer` database.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class DbalTransferRepositoryTest extends TestCase
{
    private DbalTransferRepository $repository;
    private Connection $connection;

    /** @var list<string> IDs to delete in tearDown */
    private array $insertedIds = [];

    /**
     * Fixture account UUIDs pre-created in setUp to satisfy the FK constraints
     * fk_transfers_source_account and fk_transfers_destination_account.
     *
     * The repository tests create Transfer domain objects using these hardcoded
     * UUIDs.  Now that the FK migration is applied, the accounts table must have
     * matching rows before any INSERT INTO transfers can succeed.
     */
    private const FIXTURE_ACCOUNT_IDS = [
        '11111111-1111-4111-a111-111111111111', // makeTransfer() source
        '22222222-2222-4222-a222-222222222222', // makeTransfer() destination
        '33333333-3333-4333-a333-333333333333', // testAllFieldsArePersistedCorrectly source
        '44444444-4444-4444-a444-444444444444', // testAllFieldsArePersistedCorrectly destination
    ];

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection = DriverManager::getConnection(['url' => $url]);
        $this->repository = new DbalTransferRepository($this->connection);

        // Pre-create fixture accounts to satisfy FK constraints on transfers.source_account_id
        // and transfers.destination_account_id (added in Version20260226000001).
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach (self::FIXTURE_ACCOUNT_IDS as $id) {
            $this->connection->executeStatement(
                "INSERT IGNORE INTO accounts
                    (id, owner_name, currency, balance_minor_units, status, version, created_at, updated_at)
                 VALUES (?, 'fixture', 'USD', 0, 'active', 0, ?, ?)",
                [$id, $now, $now],
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->insertedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->insertedIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM transfers WHERE id IN ({$placeholders})",
                $this->insertedIds,
            );
            $this->insertedIds = [];
        }

        // Delete fixture accounts AFTER transfers (FK order)
        $placeholders = implode(',', array_fill(0, count(self::FIXTURE_ACCOUNT_IDS), '?'));
        $this->connection->executeStatement(
            "DELETE FROM accounts WHERE id IN ({$placeholders})",
            self::FIXTURE_ACCOUNT_IDS,
        );
    }

    // ── save() + findById() ───────────────────────────────────────────────

    public function testSaveAndFindByIdRoundTrip(): void
    {
        $transfer = $this->makeTransfer();
        $this->repository->save($transfer);
        $this->track($transfer);

        $found = $this->repository->findById($transfer->getId());

        self::assertNotNull($found);
        self::assertTrue($transfer->getId()->equals($found->getId()));
        self::assertSame(TransferStatus::PENDING, $found->getStatus());
        self::assertSame(5000, $found->getAmount()->getAmountMinorUnits());
        self::assertSame('USD', $found->getAmount()->getCurrency());
    }

    public function testAllFieldsArePersistedCorrectly(): void
    {
        $id     = TransferId::generate();
        $source = AccountId::fromString('33333333-3333-4333-a333-333333333333');
        $dest   = AccountId::fromString('44444444-4444-4444-a444-444444444444');
        $amount = new Money(12345, 'EUR');

        $transfer = Transfer::initiate($id, $source, $dest, $amount);
        $this->repository->save($transfer);
        $this->track($transfer);

        $found = $this->repository->getById($id);

        self::assertTrue($found->getId()->equals($id));
        self::assertTrue($found->getSourceAccountId()->equals($source));
        self::assertTrue($found->getDestinationAccountId()->equals($dest));
        self::assertSame(12345, $found->getAmount()->getAmountMinorUnits());
        self::assertSame('EUR', $found->getAmount()->getCurrency());
        self::assertSame(TransferStatus::PENDING, $found->getStatus());
        self::assertMatchesRegularExpression('/^TXN-\d{8}-[0-9A-F]{12}$/', $found->getReference()->toString());
        self::assertNull($found->getDescription());
        self::assertNull($found->getFailureCode());
        self::assertNull($found->getFailureReason());
        self::assertNull($found->getCompletedAt());
        self::assertNull($found->getFailedAt());
        self::assertSame(0, $found->getVersion());
        self::assertInstanceOf(\DateTimeImmutable::class, $found->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $found->getUpdatedAt());
    }

    // ── findById(): null for missing ─────────────────────────────────────

    public function testFindByIdReturnsNullForNonExistentId(): void
    {
        $missing = TransferId::fromString('99999999-9999-4999-a999-999999999999');

        $result = $this->repository->findById($missing);

        self::assertNull($result);
    }

    // ── getById(): throws for missing ─────────────────────────────────────

    public function testGetByIdThrowsTransferNotFoundExceptionForNonExistentId(): void
    {
        $missing = TransferId::fromString('99999999-9999-4999-a999-999999999999');

        $this->expectException(TransferNotFoundException::class);

        $this->repository->getById($missing);
    }

    // ── save(): ON DUPLICATE KEY UPDATE ──────────────────────────────────

    public function testSaveUpdatesStatusOnSecondCall(): void
    {
        $transfer = $this->makeTransfer();
        $this->repository->save($transfer);
        $this->track($transfer);

        $transfer->markAsProcessing();
        $this->repository->save($transfer);

        $reloaded = $this->repository->getById($transfer->getId());
        self::assertSame(TransferStatus::PROCESSING, $reloaded->getStatus());
        self::assertSame(1, $reloaded->getVersion());
    }

    public function testSaveDoesNotCreateDuplicateRows(): void
    {
        $transfer = $this->makeTransfer();
        $this->repository->save($transfer);
        $this->track($transfer);

        $transfer->markAsProcessing();
        $this->repository->save($transfer);

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM transfers WHERE id = ?',
            [$transfer->getId()->toString()],
        );

        self::assertSame(1, $count);
    }

    // ── Full state-machine lifecycle ─────────────────────────────────────

    public function testFullLifecyclePendingToCompleted(): void
    {
        $transfer = $this->makeTransfer();
        $this->repository->save($transfer);
        $this->track($transfer);

        $transfer->markAsProcessing();
        $this->repository->save($transfer);

        $transfer->complete();
        $this->repository->save($transfer);

        $completed = $this->repository->getById($transfer->getId());
        self::assertSame(TransferStatus::COMPLETED, $completed->getStatus());
    }

    public function testFullLifecyclePendingToFailed(): void
    {
        $transfer = $this->makeTransfer();
        $this->repository->save($transfer);
        $this->track($transfer);

        $transfer->markAsProcessing();
        $this->repository->save($transfer);

        $transfer->fail();
        $this->repository->save($transfer);

        $failed = $this->repository->getById($transfer->getId());
        self::assertSame(TransferStatus::FAILED, $failed->getStatus());
    }

    public function testFullLifecycleToReversed(): void
    {
        $transfer = $this->makeTransfer();
        $this->repository->save($transfer);
        $this->track($transfer);

        $transfer->markAsProcessing();
        $this->repository->save($transfer);

        $transfer->complete();
        $this->repository->save($transfer);

        $transfer->reverse();
        $this->repository->save($transfer);

        $reversed = $this->repository->getById($transfer->getId());
        self::assertSame(TransferStatus::REVERSED, $reversed->getStatus());
    }
    // ── Description ───────────────────────────────────────────────────────────

    public function testDescriptionIsPersistedAndRetrieved(): void
    {
        $transfer = Transfer::initiate(
            id:                   TransferId::generate(),
            sourceAccountId:      AccountId::fromString('11111111-1111-4111-a111-111111111111'),
            destinationAccountId: AccountId::fromString('22222222-2222-4222-a222-222222222222'),
            amount:               new Money(5000, 'USD'),
            description:          'Rent payment February 2026',
        );
        $this->repository->save($transfer);
        $this->track($transfer);

        $found = $this->repository->getById($transfer->getId());
        self::assertSame('Rent payment February 2026', $found->getDescription());
    }

    // ── Failure fields ──────────────────────────────────────────────────────

    public function testFailureFieldsArePersistedCorrectly(): void
    {
        $transfer = $this->makeTransfer();
        $this->repository->save($transfer);
        $this->track($transfer);

        $transfer->markAsProcessing();
        $transfer->fail('INSUFFICIENT_FUNDS', 'Account balance too low');
        $this->repository->save($transfer);

        $reloaded = $this->repository->getById($transfer->getId());
        self::assertSame(TransferStatus::FAILED, $reloaded->getStatus());
        self::assertSame('INSUFFICIENT_FUNDS', $reloaded->getFailureCode());
        self::assertSame('Account balance too low', $reloaded->getFailureReason());
        self::assertInstanceOf(\DateTimeImmutable::class, $reloaded->getFailedAt());
        self::assertNull($reloaded->getCompletedAt());
        self::assertSame(2, $reloaded->getVersion());
    }

    // ── Completion timestamp ──────────────────────────────────────────────

    public function testCompletedAtIsPersistedOnCompletion(): void
    {
        $transfer = $this->makeTransfer();
        $this->repository->save($transfer);
        $this->track($transfer);

        $transfer->markAsProcessing();
        $transfer->complete();
        $this->repository->save($transfer);

        $reloaded = $this->repository->getById($transfer->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $reloaded->getCompletedAt());
        self::assertNull($reloaded->getFailedAt());
        self::assertNull($reloaded->getFailureCode());
        self::assertSame(2, $reloaded->getVersion());
    }

    // ── Reference uniqueness ──────────────────────────────────────────────

    public function testEachTransferHasAUniqueReference(): void
    {
        $t1 = $this->makeTransfer();
        $t2 = $this->makeTransfer();

        $this->repository->save($t1);
        $this->track($t1);
        $this->repository->save($t2);
        $this->track($t2);

        self::assertNotSame(
            $t1->getReference()->toString(),
            $t2->getReference()->toString(),
        );
    }
    // ── Datetime persistence ─────────────────────────────────────────────

    public function testCreatedAtIsPreservedAcrossSaveCycles(): void
    {
        $transfer = $this->makeTransfer();
        $originalCreatedAt = $transfer->getCreatedAt();

        $this->repository->save($transfer);
        $this->track($transfer);

        $transfer->markAsProcessing();
        $this->repository->save($transfer);

        $reloaded = $this->repository->getById($transfer->getId());

        // MySQL DATETIME(6) truncates to microsecond, so compare at second precision
        self::assertSame(
            $originalCreatedAt->format('Y-m-d H:i:s'),
            $reloaded->getCreatedAt()->format('Y-m-d H:i:s'),
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function makeTransfer(): Transfer
    {
        return Transfer::initiate(
            id: TransferId::generate(),
            sourceAccountId: AccountId::fromString('11111111-1111-4111-a111-111111111111'),
            destinationAccountId: AccountId::fromString('22222222-2222-4222-a222-222222222222'),
            amount: new Money(5000, 'USD'),
        );
    }

    private function track(Transfer $transfer): void
    {
        $this->insertedIds[] = $transfer->getId()->toString();
    }
}
