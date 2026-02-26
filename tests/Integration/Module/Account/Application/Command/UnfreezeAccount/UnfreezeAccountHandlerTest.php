<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Account\Application\Command\UnfreezeAccount;

use App\Module\Account\Application\Command\UnfreezeAccount\UnfreezeAccountCommand;
use App\Module\Account\Application\Command\UnfreezeAccount\UnfreezeAccountHandler;
use App\Module\Account\Domain\Event\AccountUnfrozen;
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
 * Integration tests for UnfreezeAccountHandler against the real MySQL container.
 *
 * Verifies that AccountUnfrozen is written to outbox_events atomically within
 * the same transaction as the accounts UPDATE.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class UnfreezeAccountHandlerTest extends TestCase
{
    private Connection              $connection;
    private DbalAccountRepository   $accountRepo;
    private DbalOutboxRepository    $outboxRepo;
    private UnfreezeAccountHandler  $handler;

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

        $this->handler = new UnfreezeAccountHandler(
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

    // ── Happy path: FROZEN → ACTIVE ───────────────────────────────────────────

    public function testUnfreezeFrozenAccountTransitionsToActive(): void
    {
        $account = $this->openFrozenAndSave('USD');

        $dto = ($this->handler)(new UnfreezeAccountCommand($account->getId()->toString()));

        self::assertSame('active', $dto->status);

        $persisted = $this->accountRepo->findById($account->getId());
        self::assertNotNull($persisted);
        self::assertSame(AccountStatus::ACTIVE, $persisted->getStatus());
    }

    public function testUnfreezeAccountWritesAccountUnfrozenToOutbox(): void
    {
        $account = $this->openFrozenAndSave('EUR');
        $accountId = $account->getId()->toString();

        ($this->handler)(new UnfreezeAccountCommand($accountId));

        $row = $this->connection->fetchAssociative(
            "SELECT event_type, aggregate_type, aggregate_id, payload, published_at, attempt_count
             FROM outbox_events
             WHERE aggregate_type = 'Account' AND aggregate_id = ?
             ORDER BY occurred_at DESC
             LIMIT 1",
            [$accountId],
        );

        self::assertNotFalse($row, 'Expected outbox row for AccountUnfrozen');
        self::assertSame(AccountUnfrozen::class, $row['event_type']);
        self::assertSame('Account', $row['aggregate_type']);
        self::assertSame($accountId, $row['aggregate_id']);
        self::assertNull($row['published_at'], 'New outbox row must be unpublished');
        self::assertSame(0, (int) $row['attempt_count']);

        $payload = json_decode($row['payload'], true);
        self::assertSame($accountId, $payload['account_id']);
        self::assertArrayHasKey('occurred_at', $payload);
    }

    // ── Not frozen → 409 ─────────────────────────────────────────────────────

    public function testUnfreezeActiveAccountThrowsInvalidAccountStateException(): void
    {
        $this->expectException(InvalidAccountStateException::class);

        $account = $this->openActiveAndSave('USD');
        // Account is ACTIVE, not FROZEN — must throw
        ($this->handler)(new UnfreezeAccountCommand($account->getId()->toString()));
    }

    // ── Not found → 404 ──────────────────────────────────────────────────────

    public function testUnfreezeNonExistentAccountThrowsAccountNotFoundException(): void
    {
        $this->expectException(AccountNotFoundException::class);

        ($this->handler)(new UnfreezeAccountCommand('ffffffff-ffff-4fff-bfff-ffffffffffff'));
    }

    // ── No outbox row written on error ────────────────────────────────────────

    public function testNoOutboxRowWrittenWhenUnfreezeThrows(): void
    {
        $account = $this->openActiveAndSave('USD');
        $accountId = $account->getId()->toString();

        try {
            ($this->handler)(new UnfreezeAccountCommand($accountId));
        } catch (InvalidAccountStateException) {
            // expected — account was ACTIVE
        }

        // The tearDown would also clean up, but verify no partial outbox row exists.
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM outbox_events WHERE aggregate_id = ?",
            [$accountId],
        );

        self::assertSame(0, $count, 'No outbox row must be written when the handler throws');
    }

    // ── Freeze-then-unfreeze cycle ────────────────────────────────────────────

    public function testFreezeUnfreezeCycleLeavesAccountActive(): void
    {
        $account = $this->openFrozenAndSave('GBP');
        $accountId = $account->getId()->toString();

        ($this->handler)(new UnfreezeAccountCommand($accountId));

        $persisted = $this->accountRepo->findById(AccountId::fromString($accountId));
        self::assertSame(AccountStatus::ACTIVE, $persisted->getStatus());

        // Exactly one AccountUnfrozen outbox row from this handler
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM outbox_events
             WHERE aggregate_type = 'Account' AND aggregate_id = ?
               AND event_type = ?",
            [$accountId, AccountUnfrozen::class],
        );
        self::assertSame(1, $count);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Open an account in ACTIVE status and persist it.
     */
    private function openActiveAndSave(string $currency): Account
    {
        $id      = AccountId::fromString((string) Uuid::v4());
        $account = Account::open($id, 'Test Owner', $currency, 0);
        $this->accountRepo->save($account);
        $account->releaseEvents();
        $this->accountIds[] = $id->toString();

        return $account;
    }

    /**
     * Open an account, immediately freeze it, persist, and return it FROZEN.
     */
    private function openFrozenAndSave(string $currency): Account
    {
        $id      = AccountId::fromString((string) Uuid::v4());
        $account = Account::open($id, 'Test Owner', $currency, 0);
        $this->accountRepo->save($account);
        $account->releaseEvents();

        $account->freeze();
        $this->accountRepo->save($account);
        $account->releaseEvents();

        $this->accountIds[] = $id->toString();

        return $account;
    }
}
