<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Account\Infrastructure\Persistence;

use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\Model\AccountStatus;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Domain\ValueObject\Balance;
use App\Module\Account\Infrastructure\Persistence\DbalAccountRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for DbalAccountRepository against the real MySQL container.
 *
 * Builds a raw DBAL Connection from DATABASE_URL (populated by bootEnv()
 * in bootstrap.php) — no Symfony kernel required.
 * Each test cleans up its own inserted rows in tearDown().
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class DbalAccountRepositoryTest extends TestCase
{
    private DbalAccountRepository $repository;
    private Connection $connection;

    /** @var list<string> IDs to delete in tearDown */
    private array $insertedIds = [];

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection = DriverManager::getConnection(['url' => $url]);
        $this->repository = new DbalAccountRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->insertedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->insertedIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM accounts WHERE id IN ({$placeholders})",
                $this->insertedIds,
            );
            $this->insertedIds = [];
        }
    }

    // ── save() + findById() ───────────────────────────────────────────────

    public function testSaveAndFindByIdRoundTrip(): void
    {
        $account = $this->makeAccount();
        $this->repository->save($account);
        $this->track($account);

        $found = $this->repository->findById($account->getId());

        self::assertNotNull($found);
        self::assertTrue($account->getId()->equals($found->getId()));
        self::assertSame(AccountStatus::ACTIVE, $found->getStatus());
        self::assertSame(5000, $found->getBalance()->getAmountMinorUnits());
        self::assertSame('USD', $found->getCurrency());
        self::assertSame('Test Owner', $found->getOwnerName());
    }

    public function testAllFieldsArePersistedCorrectly(): void
    {
        $id      = AccountId::fromString('cccccccc-cccc-4ccc-accc-cccccccccccc');
        $account = Account::open($id, 'Jane Doe', 'EUR', 12345);
        $this->repository->save($account);
        $this->track($account);

        $found = $this->repository->getById($id);

        self::assertTrue($found->getId()->equals($id));
        self::assertSame('Jane Doe', $found->getOwnerName());
        self::assertSame('EUR', $found->getCurrency());
        self::assertSame(12345, $found->getBalance()->getAmountMinorUnits());
        self::assertSame(AccountStatus::ACTIVE, $found->getStatus());
        self::assertSame(0, $found->getVersion());
        self::assertInstanceOf(\DateTimeImmutable::class, $found->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $found->getUpdatedAt());
    }

    // ── findById(): null for missing ─────────────────────────────────────

    public function testFindByIdReturnsNullForNonExistentId(): void
    {
        $missing = AccountId::fromString('dddddddd-dddd-4ddd-addd-dddddddddddd');

        self::assertNull($this->repository->findById($missing));
    }

    // ── getById(): throws for missing ─────────────────────────────────────

    public function testGetByIdThrowsForNonExistentId(): void
    {
        $this->expectException(AccountNotFoundException::class);

        $missing = AccountId::fromString('eeeeeeee-eeee-4eee-aeee-eeeeeeeeeeee');
        $this->repository->getById($missing);
    }

    // ── save(): ON DUPLICATE KEY UPDATE ──────────────────────────────────

    public function testSaveUpdatesBalanceOnSecondCall(): void
    {
        $account = $this->makeAccount(5000);
        $this->repository->save($account);
        $this->track($account);

        $account->debit(new Balance(2000, 'USD'), 'ffffffff-ffff-4fff-afff-ffffffffffff');
        $this->repository->save($account);

        $reloaded = $this->repository->getById($account->getId());
        self::assertSame(3000, $reloaded->getBalance()->getAmountMinorUnits());
        self::assertSame(1, $reloaded->getVersion());
    }

    public function testSaveDoesNotCreateDuplicateRows(): void
    {
        $account = $this->makeAccount();
        $this->repository->save($account);
        $this->track($account);
        $this->repository->save($account); // second call must upsert, not insert

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM accounts WHERE id = ?',
            [$account->getId()->toString()],
        );

        self::assertSame(1, $count);
    }

    // ── Debit + Credit persistence ────────────────────────────────────────

    public function testDebitAndCreditBalanceIsPersisted(): void
    {
        $account = $this->makeAccount(10000);
        $this->repository->save($account);
        $this->track($account);

        $transferId = '11111111-1111-4111-a111-111111111111';
        $account->debit(new Balance(3000, 'USD'), $transferId);
        $account->credit(new Balance(500, 'USD'), $transferId);
        $this->repository->save($account);

        $reloaded = $this->repository->getById($account->getId());
        self::assertSame(7500, $reloaded->getBalance()->getAmountMinorUnits());
        self::assertSame(2, $reloaded->getVersion());
    }

    // ── Freeze persistence ────────────────────────────────────────────────

    public function testFreezeStatusIsPersisted(): void
    {
        $account = $this->makeAccount();
        $this->repository->save($account);
        $this->track($account);

        $account->freeze();
        $this->repository->save($account);

        $reloaded = $this->repository->getById($account->getId());
        self::assertSame(AccountStatus::FROZEN, $reloaded->getStatus());
        self::assertSame(1, $reloaded->getVersion());
    }

    // ── DateTime persistence ─────────────────────────────────────────────

    public function testCreatedAtIsPreservedAcrossSaveCycles(): void
    {
        $account           = $this->makeAccount();
        $originalCreatedAt = $account->getCreatedAt();
        $this->repository->save($account);
        $this->track($account);

        $account->credit(new Balance(100, 'USD'), '22222222-2222-4222-a222-222222222222');
        $this->repository->save($account);

        $reloaded = $this->repository->getById($account->getId());
        self::assertSame(
            $originalCreatedAt->format('Y-m-d H:i:s'),
            $reloaded->getCreatedAt()->format('Y-m-d H:i:s'),
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function makeAccount(int $balance = 5000): Account
    {
        // Generate a fresh UUID per invocation so parallel test runs and
        // repeated setUp/tearDown cycles cannot collide on the same primary key.
        return Account::open(
            id:                       AccountId::fromString((string) Uuid::v4()),
            ownerName:                'Test Owner',
            currency:                 'USD',
            initialBalanceMinorUnits: $balance,
        );
    }

    private function track(Account $account): void
    {
        $this->insertedIds[] = $account->getId()->toString();
    }
}
