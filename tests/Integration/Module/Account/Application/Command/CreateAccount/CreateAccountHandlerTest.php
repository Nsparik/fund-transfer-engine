<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Account\Application\Command\CreateAccount;

use App\Module\Account\Application\Command\CreateAccount\CreateAccountCommand;
use App\Module\Account\Application\Command\CreateAccount\CreateAccountHandler;
use App\Module\Account\Domain\Event\AccountCreated;
use App\Module\Account\Domain\Model\AccountStatus;
use App\Module\Account\Domain\ValueObject\AccountId;
use App\Module\Account\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Ledger\Infrastructure\Persistence\DbalLedgerRepository;
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
 * Integration tests for CreateAccountHandler against the real MySQL container.
 *
 * Verifies that AccountCreated is written to outbox_events atomically within
 * the same transaction as the accounts INSERT.
 *
 * Run with:
 *   docker compose exec php php vendor/bin/phpunit --testsuite Integration
 */
final class CreateAccountHandlerTest extends TestCase
{
    private Connection              $connection;
    private DbalAccountRepository   $accountRepo;
    private DbalOutboxRepository    $outboxRepo;
    private CreateAccountHandler    $handler;

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
        $ledgerRepo        = new DbalLedgerRepository($this->connection);

        $this->handler = new CreateAccountHandler(
            $this->accountRepo,
            $txManager,
            $dispatcher,
            new NullLogger(),
            $this->outboxRepo,
            $serializer,
            $ledgerRepo,
        );
    }

    protected function tearDown(): void
    {
        if ($this->accountIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->accountIds), '?'));
            // Delete ledger_entries first — fk_ledger_entries_account is RESTRICT,
            // so the accounts DELETE will fail if ledger rows still reference them.
            $this->connection->executeStatement(
                "DELETE FROM ledger_entries WHERE account_id IN ({$placeholders})",
                $this->accountIds,
            );
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

    // ── Happy path ────────────────────────────────────────────────────────────

    public function testCreateAccountPersistsAccountRow(): void
    {
        $accountId = (string) Uuid::v4();
        $this->accountIds[] = $accountId;

        $dto = ($this->handler)(new CreateAccountCommand($accountId, 'Jane Doe', 'USD', 0));

        self::assertSame($accountId, $dto->id);
        self::assertSame('active', $dto->status);
        self::assertSame(0, $dto->balanceMinorUnits);
        self::assertSame('USD', $dto->currency);

        $persisted = $this->accountRepo->findById(AccountId::fromString($accountId));
        self::assertNotNull($persisted);
        self::assertSame(AccountStatus::ACTIVE, $persisted->getStatus());
    }

    public function testCreateAccountWritesAccountCreatedToOutbox(): void
    {
        $accountId = (string) Uuid::v4();
        $this->accountIds[] = $accountId;

        ($this->handler)(new CreateAccountCommand($accountId, 'Jane Doe', 'USD', 1_500));

        $row = $this->connection->fetchAssociative(
            "SELECT event_type, aggregate_type, aggregate_id, payload
             FROM outbox_events
             WHERE aggregate_type = 'Account' AND aggregate_id = ?
             ORDER BY occurred_at ASC
             LIMIT 1",
            [$accountId],
        );

        self::assertNotFalse($row, 'Expected one outbox row for AccountCreated');
        self::assertSame(AccountCreated::class, $row['event_type']);
        self::assertSame('Account', $row['aggregate_type']);
        self::assertSame($accountId, $row['aggregate_id']);

        $payload = json_decode($row['payload'], true);
        self::assertSame($accountId, $payload['account_id']);
        self::assertSame('Jane Doe', $payload['owner_name']);
        self::assertSame(1_500, $payload['initial_balance_minor_units']);
        self::assertSame('USD', $payload['currency']);
    }

    public function testCreateAccountOutboxRowIsUnpublishedByDefault(): void
    {
        $accountId = (string) Uuid::v4();
        $this->accountIds[] = $accountId;

        ($this->handler)(new CreateAccountCommand($accountId, 'Test', 'EUR', 0));

        $row = $this->connection->fetchAssociative(
            "SELECT published_at, attempt_count FROM outbox_events
             WHERE aggregate_type = 'Account' AND aggregate_id = ?",
            [$accountId],
        );

        self::assertNotFalse($row);
        self::assertNull($row['published_at'], 'New outbox row must be unpublished');
        self::assertSame(0, (int) $row['attempt_count']);
    }

    public function testCreateAccountWithNonZeroInitialBalance(): void
    {
        $accountId = (string) Uuid::v4();
        $this->accountIds[] = $accountId;

        $dto = ($this->handler)(new CreateAccountCommand($accountId, 'Rich User', 'GBP', 100_000));

        self::assertSame(100_000, $dto->balanceMinorUnits);
        self::assertSame('GBP', $dto->currency);

        $row = $this->connection->fetchAssociative(
            "SELECT payload FROM outbox_events
             WHERE aggregate_type = 'Account' AND aggregate_id = ?",
            [$accountId],
        );

        $payload = json_decode($row['payload'], true);
        self::assertSame(100_000, $payload['initial_balance_minor_units']);
        self::assertSame('GBP', $payload['currency']);
    }

    public function testCreateAccountWithNonZeroBalanceWritesBootstrapLedgerEntry(): void
    {
        $accountId = (string) Uuid::v4();
        $this->accountIds[] = $accountId;

        ($this->handler)(new CreateAccountCommand($accountId, 'Bootstrap User', 'EUR', 5_000));

        $row = $this->connection->fetchAssociative(
            "SELECT account_id, entry_type, transfer_type, amount_minor_units,
                    balance_after_minor_units, currency, counterparty_account_id
             FROM ledger_entries
             WHERE account_id = ?
             ORDER BY occurred_at ASC
             LIMIT 1",
            [$accountId],
        );

        self::assertNotFalse($row, 'Expected a bootstrap ledger entry to be written');
        self::assertSame($accountId, $row['account_id']);
        self::assertSame('credit',    $row['entry_type']);
        self::assertSame('bootstrap', $row['transfer_type']);
        self::assertSame(5_000, (int) $row['amount_minor_units']);
        self::assertSame(5_000, (int) $row['balance_after_minor_units']);
        self::assertSame('EUR', $row['currency']);
        // counterparty_account_id must be the synthetic system UUID (no FK required)
        self::assertSame('00000000-0000-7000-8000-000000000000', $row['counterparty_account_id']);
    }

    public function testCreateAccountWithZeroBalanceWritesNoLedgerEntry(): void
    {
        $accountId = (string) Uuid::v4();
        $this->accountIds[] = $accountId;

        ($this->handler)(new CreateAccountCommand($accountId, 'Zero User', 'USD', 0));

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE account_id = ?',
            [$accountId],
        );

        self::assertSame(0, $count, 'No ledger entry should be written for a zero-balance account');
    }
}

