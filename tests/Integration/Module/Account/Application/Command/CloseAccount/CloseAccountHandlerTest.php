<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Account\Application\Command\CloseAccount;

use App\Module\Account\Application\Command\CloseAccount\CloseAccountCommand;
use App\Module\Account\Application\Command\CloseAccount\CloseAccountHandler;
use App\Module\Account\Domain\Exception\AccountNotFoundException;
use App\Module\Account\Domain\Exception\InvalidAccountStateException;
use App\Module\Account\Domain\Exception\NonZeroBalanceOnCloseException;
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
 * Integration tests for CloseAccountHandler against the real MySQL container.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class CloseAccountHandlerTest extends TestCase
{
    private Connection            $connection;
    private DbalAccountRepository $accountRepo;
    private CloseAccountHandler   $handler;

    /** @var list<string> Account IDs to delete in tearDown */
    private array $accountIds = [];

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? throw new \RuntimeException(
            'DATABASE_URL is not set. Is bootstrap.php loading the .env file?'
        );

        $this->connection  = DriverManager::getConnection(['url' => $url]);
        $this->accountRepo = new DbalAccountRepository($this->connection);
        $txManager         = new DbalTransactionManager($this->connection);
        $dispatcher        = new EventDispatcher();
        $outbox            = new DbalOutboxRepository($this->connection);
        $serializer        = new OutboxEventSerializer();

        $this->handler = new CloseAccountHandler(
            $this->accountRepo,
            $txManager,
            $dispatcher,
            new NullLogger(),
            $outbox,
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
            // Clean up outbox events written during the test
            $this->connection->executeStatement(
                "DELETE FROM outbox_events WHERE aggregate_type = 'Account' AND aggregate_id IN ({$placeholders})",
                $this->accountIds,
            );
            $this->accountIds = [];
        }
    }

    // ── Happy path: ACTIVE → CLOSED ──────────────────────────────────────────

    public function testCloseActiveAccountTransitionsToClosedAndPersists(): void
    {
        $account = $this->openAndSave('USD', 0);

        $dto = ($this->handler)(new CloseAccountCommand($account->getId()->toString()));

        self::assertSame('closed', $dto->status);

        // Verify persisted state via a fresh load
        $persisted = $this->accountRepo->findById($account->getId());
        self::assertNotNull($persisted);
        self::assertSame(AccountStatus::CLOSED, $persisted->getStatus());
        self::assertSame(0, $persisted->getBalance()->getAmountMinorUnits());
    }

    // ── Happy path: FROZEN → CLOSED ──────────────────────────────────────────

    public function testCloseFrozenAccountTransitionsToClosed(): void
    {
        $account = $this->openAndSave('EUR', 0);
        // Freeze manually via domain model + persist
        $account->freeze();
        $this->accountRepo->save($account);
        $account->releaseEvents();

        $dto = ($this->handler)(new CloseAccountCommand($account->getId()->toString()));

        self::assertSame('closed', $dto->status);

        $persisted = $this->accountRepo->findById($account->getId());
        self::assertSame(AccountStatus::CLOSED, $persisted->getStatus());
    }

    // ── Non-zero balance → 409 ────────────────────────────────────────────────

    public function testCloseAccountWithNonZeroBalanceThrowsNonZeroBalanceOnCloseException(): void
    {
        $this->expectException(NonZeroBalanceOnCloseException::class);

        $account = $this->openAndSave('USD', 5000);
        ($this->handler)(new CloseAccountCommand($account->getId()->toString()));
    }

    // ── Already closed → 409 ─────────────────────────────────────────────────

    public function testCloseAlreadyClosedAccountThrowsInvalidAccountStateException(): void
    {
        $this->expectException(InvalidAccountStateException::class);
        $this->expectExceptionMessage('closed');

        $account = $this->openAndSave('USD', 0);
        ($this->handler)(new CloseAccountCommand($account->getId()->toString()));
        // Second close attempt must throw
        ($this->handler)(new CloseAccountCommand($account->getId()->toString()));
    }

    // ── Not found → 404 ──────────────────────────────────────────────────────

    public function testCloseNonExistentAccountThrowsAccountNotFoundException(): void
    {
        $this->expectException(AccountNotFoundException::class);

        ($this->handler)(new CloseAccountCommand('ffffffff-ffff-4fff-bfff-ffffffffffff'));
    }

    // ── Transfer attempt on closed account ───────────────────────────────────

    public function testClosedAccountCannotBeDebited(): void
    {
        $account = $this->openAndSave('USD', 0);
        ($this->handler)(new CloseAccountCommand($account->getId()->toString()));

        $closed = $this->accountRepo->findById($account->getId());

        $this->expectException(\App\Module\Account\Domain\Exception\AccountClosedException::class);
        $closed->debit(new \App\Module\Account\Domain\ValueObject\Balance(100, 'USD'), 'test-transfer-id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function openAndSave(string $currency, int $balance): Account
    {
        $id      = AccountId::fromString((string) Uuid::v4());
        $account = Account::open($id, 'Test Owner', $currency, $balance);
        $this->accountRepo->save($account);
        $account->releaseEvents();
        $this->accountIds[] = $id->toString();

        return $account;
    }
}
