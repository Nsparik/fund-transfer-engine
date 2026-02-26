<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Account\Application\Command\FreezeAccount;

use App\Module\Account\Application\Command\FreezeAccount\FreezeAccountCommand;
use App\Module\Account\Application\Command\FreezeAccount\FreezeAccountHandler;
use App\Module\Account\Domain\Event\AccountFrozen;
use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Exception\InvalidAccountStateException;
use App\Module\Account\Domain\Model\Account;
use App\Module\Account\Domain\Model\AccountStatus;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Infrastructure\Persistence\DbalAccountRepository;
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
 * Integration tests for FreezeAccountHandler against the real MySQL container.
 *
 * Verifies that AccountFrozen is written to outbox_events atomically within
 * the same transaction as the accounts UPDATE.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class FreezeAccountHandlerTest extends TestCase
{
    private Connection              $connection;
    private DbalAccountRepository   $accountRepo;
    private DbalOutboxRepository    $outboxRepo;
    private FreezeAccountHandler    $handler;

    /** @var list<string> Account IDs to delete in tearDown */
    private array $accountIds = [];

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection  = DriverManager::getConnection(['url' => $url]);
        $this->accountRepo = new DbalAccountRepository($this->connection);
        $this->outboxRepo  = new DbalOutboxRepository($this->connection);
        $txManager         = new DbalTransactionManager($this->connection);
        $dispatcher        = new EventDispatcher();
        $serializer        = new OutboxEventSerializer();

        $this->handler = new FreezeAccountHandler(
            $this->accountRepo,
            $txManager,
            $dispatcher,
            new NullLogger(),
            $this->outboxRepo,
            $serializer,
        );
    }

    protected function tearDown(): void
    {
        if ($this->accountIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->accountIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM accounts WHERE id IN ({$placeholders})",
                $this->accountIds,
            );
            $this->connection->executeStatement(
                "DELETE FROM outbox_events WHERE aggregate_type = 'Account' AND aggregate_id IN ({$placeholders})",
                $this->accountIds,
            );
            $this->accountIds = [];
        }
    }

    // ── Happy path: ACTIVE → FROZEN ───────────────────────────────────────────

    public function testFreezeActiveAccountTransitionsToFrozen(): void
    {
        $account = $this->openAndSave('USD');

        $dto = ($this->handler)(new FreezeAccountCommand($account->getId()->toString()));

        self::assertSame('frozen', $dto->status);

        $persisted = $this->accountRepo->findById($account->getId());
        self::assertNotNull($persisted);
        self::assertSame(AccountStatus::FROZEN, $persisted->getStatus());
    }

    public function testFreezeAccountWritesAccountFrozenToOutbox(): void
    {
        $account = $this->openAndSave('EUR');
        $accountId = $account->getId()->toString();

        ($this->handler)(new FreezeAccountCommand($accountId));

        $row = $this->connection->fetchAssociative(
            "SELECT event_type, aggregate_type, aggregate_id, payload, published_at, attempt_count
             FROM outbox_events
             WHERE aggregate_type = 'Account' AND aggregate_id = ?
             ORDER BY occurred_at DESC
             LIMIT 1",
            [$accountId],
        );

        self::assertNotFalse($row, 'Expected outbox row for AccountFrozen');
        self::assertSame(AccountFrozen::class, $row['event_type']);
        self::assertSame('Account', $row['aggregate_type']);
        self::assertSame($accountId, $row['aggregate_id']);
        self::assertNull($row['published_at'], 'New outbox row must be unpublished');
        self::assertSame(0, (int) $row['attempt_count']);

        $payload = json_decode($row['payload'], true);
        self::assertSame($accountId, $payload['account_id']);
        self::assertArrayHasKey('occurred_at', $payload);
    }

    // ── Already frozen → 409 ─────────────────────────────────────────────────

    public function testFreezeAlreadyFrozenAccountThrowsInvalidAccountStateException(): void
    {
        $this->expectException(InvalidAccountStateException::class);

        $account = $this->openAndSave('USD');
        ($this->handler)(new FreezeAccountCommand($account->getId()->toString()));
        // Second freeze must throw — account is already FROZEN
        ($this->handler)(new FreezeAccountCommand($account->getId()->toString()));
    }

    // ── Not found → 404 ──────────────────────────────────────────────────────

    public function testFreezeNonExistentAccountThrowsAccountNotFoundException(): void
    {
        $this->expectException(AccountNotFoundException::class);

        ($this->handler)(new FreezeAccountCommand('ffffffff-ffff-4fff-bfff-ffffffffffff'));
    }

    // ── No outbox row written on error ────────────────────────────────────────

    public function testNoOutboxRowWrittenWhenAccountNotFound(): void
    {
        $nonExistentId = (string) Uuid::v4();

        try {
            ($this->handler)(new FreezeAccountCommand($nonExistentId));
        } catch (AccountNotFoundException) {
            // expected
        }

        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM outbox_events WHERE aggregate_id = ?",
            [$nonExistentId],
        );

        self::assertSame(0, $count, 'No outbox row must be written when the handler throws');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function openAndSave(string $currency): Account
    {
        $id      = AccountId::fromString((string) Uuid::v4());
        $account = Account::open($id, 'Test Owner', $currency, 0);
        $this->accountRepo->save($account);
        $account->releaseEvents();
        $this->accountIds[] = $id->toString();

        return $account;
    }
}
