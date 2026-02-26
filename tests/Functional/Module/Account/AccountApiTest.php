<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Account;

use App\Tests\Functional\AbstractFunctionalTestCase;

/**
 * Functional tests for the Account API — full HTTP-stack coverage.
 *
 * ## Coverage (22 tests — test 18 excluded pending date-range filter)
 *
 *   POST /accounts
 *     - Happy path: 201, status=active, Location header
 *     - Blank ownerName → 400 VALIDATION_ERROR with violations
 *     - Invalid currency (not 3-char uppercase) → 400 VALIDATION_ERROR
 *     - Negative initialBalanceMinorUnits → 400 VALIDATION_ERROR
 *     - With initial balance: balanceMinorUnits matches supplied value
 *     - Malformed JSON body → 400 INVALID_JSON
 *
 *   GET /accounts/{id}
 *     - Returns 200 with all 8 DTO fields
 *     - Unknown UUID → 404 ACCOUNT_NOT_FOUND
 *
 *   POST /accounts/{id}/freeze
 *     - Active account transitions to frozen
 *     - Already-frozen account → 409 INVALID_ACCOUNT_STATE
 *     - Closed account → 409 INVALID_ACCOUNT_STATE
 *
 *   POST /accounts/{id}/unfreeze
 *     - Frozen account transitions back to active
 *     - Active account → 409 INVALID_ACCOUNT_STATE
 *
 *   POST /accounts/{id}/close
 *     - Zero-balance account closes successfully: 200, status=closed
 *     - Non-zero balance → 409 NON_ZERO_BALANCE_ON_CLOSE
 *     - Already-closed account → 409 INVALID_ACCOUNT_STATE
 *
 *   GET /accounts/{id}/transfers
 *     - Returns 200 with items + pagination keys
 *     - status filter: only specified status returned
 *     - Invalid status → 400 INVALID_STATUS
 *     - Invalid per_page → 400 INVALID_PER_PAGE
 *     - Account sees transfers where it is source OR destination
 *
 *   POST /transfers (edge case via Account state)
 *     - Transfer to closed destination account → 409 ACCOUNT_CLOSED
 *     - Transfer from closed source account → 409 ACCOUNT_CLOSED
 *
 * ## Notes
 *   - Test 18 (date-range filter on /accounts/{id}/transfers) is excluded
 *     pending date-range filter implementation.
 *   - currency input is normalised to uppercase by CreateAccountRequest, so
 *     passing a digit-only string still fails the /^[A-Z]{3}$/ regex.
 *   - NON_ZERO_BALANCE_ON_CLOSE maps to 409 via DomainExceptionListener.
 */
final class AccountApiTest extends AbstractFunctionalTestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts — create
    // ──────────────────────────────────────────────────────────────────────────

    public function testCreateAccountReturns201(): void
    {
        $body = $this->postJson('/accounts', [
            'ownerName'                => 'Alice Smith',
            'currency'                 => 'USD',
            'initialBalanceMinorUnits' => 0,
        ]);

        self::assertSame(201, $this->getStatusCode());

        $data = $body['data'];
        $this->trackAccount($data['id']);

        self::assertNotEmpty($data['id']);
        self::assertSame('Alice Smith', $data['ownerName']);
        self::assertSame('USD', $data['currency']);
        self::assertSame(0, $data['balanceMinorUnits']);
        self::assertSame('active', $data['status']);
        self::assertSame('/accounts/' . $data['id'], $this->getResponseHeader('Location'));
    }

    public function testCreateAccountReturns400OnBlankOwnerName(): void
    {
        $body = $this->postJson('/accounts', [
            'ownerName' => '',
            'currency'  => 'USD',
        ]);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
        self::assertNotEmpty($body['error']['violations']);
        self::assertSame('ownerName', $body['error']['violations'][0]['field']);
    }

    public function testCreateAccountReturns400OnInvalidCurrency(): void
    {
        $body = $this->postJson('/accounts', [
            'ownerName' => 'Bob Jones',
            'currency'  => '123',
        ]);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);

        $fields = array_column($body['error']['violations'], 'field');
        self::assertContains('currency', $fields);
    }

    public function testCreateAccountReturns400OnNegativeInitialBalance(): void
    {
        $body = $this->postJson('/accounts', [
            'ownerName'                => 'Carol White',
            'currency'                 => 'EUR',
            'initialBalanceMinorUnits' => -1,
        ]);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);

        $fields = array_column($body['error']['violations'], 'field');
        self::assertContains('initialBalanceMinorUnits', $fields);
    }

    public function testCreateAccountWithInitialBalance(): void
    {
        $body = $this->postJson('/accounts', [
            'ownerName'                => 'Dave Brown',
            'currency'                 => 'GBP',
            'initialBalanceMinorUnits' => 5_000,
        ]);

        self::assertSame(201, $this->getStatusCode());

        $data = $body['data'];
        $this->trackAccount($data['id']);

        self::assertSame(5_000, $data['balanceMinorUnits']);
        self::assertSame('GBP', $data['currency']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /accounts/{id}
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetAccountReturns200(): void
    {
        $created = $this->createAccount('Eve Green', 'USD', 1_000);

        $body = $this->getJson('/accounts/' . $created['id']);

        self::assertSame(200, $this->getStatusCode());

        $data = $body['data'];
        self::assertSame($created['id'], $data['id']);
        self::assertSame('Eve Green', $data['ownerName']);
        self::assertSame('USD', $data['currency']);
        self::assertSame(1_000, $data['balanceMinorUnits']);
        self::assertSame('active', $data['status']);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
    }

    public function testGetAccountReturns404(): void
    {
        $body = $this->getJson('/accounts/00000000-0000-4000-8000-000000000000');

        self::assertSame(404, $this->getStatusCode());
        self::assertSame('ACCOUNT_NOT_FOUND', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts/{id}/freeze
    // ──────────────────────────────────────────────────────────────────────────

    public function testFreezeAccountTransitionsToFrozen(): void
    {
        $account = $this->createAccount('Frank Black', 'USD', 0);

        $body = $this->postJson('/accounts/' . $account['id'] . '/freeze');

        self::assertSame(200, $this->getStatusCode());
        self::assertSame('frozen', $body['data']['status']);
        self::assertSame($account['id'], $body['data']['id']);
    }

    public function testFreezeAlreadyFrozenReturns409(): void
    {
        $account = $this->createAccount('Grace Hill', 'USD', 0);
        $this->postJson('/accounts/' . $account['id'] . '/freeze');

        $body = $this->postJson('/accounts/' . $account['id'] . '/freeze');

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('INVALID_ACCOUNT_STATE', $body['error']['code']);
    }

    public function testFreezeClosedAccountReturns409(): void
    {
        $account = $this->createAccount('Henry Ford', 'USD', 0);
        $this->postJson('/accounts/' . $account['id'] . '/close');

        $body = $this->postJson('/accounts/' . $account['id'] . '/freeze');

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('INVALID_ACCOUNT_STATE', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts/{id}/unfreeze
    // ──────────────────────────────────────────────────────────────────────────

    public function testUnfreezeAccountTransitionsToActive(): void
    {
        $account = $this->createAccount('Iris Lane', 'USD', 0);
        $this->postJson('/accounts/' . $account['id'] . '/freeze');

        $body = $this->postJson('/accounts/' . $account['id'] . '/unfreeze');

        self::assertSame(200, $this->getStatusCode());
        self::assertSame('active', $body['data']['status']);
    }

    public function testUnfreezeActiveAccountReturns409(): void
    {
        $account = $this->createAccount('Jake Stone', 'USD', 0);

        $body = $this->postJson('/accounts/' . $account['id'] . '/unfreeze');

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('INVALID_ACCOUNT_STATE', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts/{id}/close
    // ──────────────────────────────────────────────────────────────────────────

    public function testCloseAccountWithZeroBalanceReturns200(): void
    {
        $account = $this->createAccount('Kim Taylor', 'USD', 0);

        $body = $this->postJson('/accounts/' . $account['id'] . '/close');

        self::assertSame(200, $this->getStatusCode());
        self::assertSame('closed', $body['data']['status']);
    }

    public function testCloseAccountWithNonZeroBalanceReturns409(): void
    {
        $account = $this->createAccount('Leo Martin', 'USD', 1_000);

        $body = $this->postJson('/accounts/' . $account['id'] . '/close');

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('NON_ZERO_BALANCE_ON_CLOSE', $body['error']['code']);
    }

    public function testCloseAlreadyClosedReturns409(): void
    {
        $account = $this->createAccount('Mia Clark', 'USD', 0);
        $this->postJson('/accounts/' . $account['id'] . '/close');

        $body = $this->postJson('/accounts/' . $account['id'] . '/close');

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('INVALID_ACCOUNT_STATE', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /accounts/{id}/transfers
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetAccountTransfersReturnsPaginatedList(): void
    {
        $src = $this->createAccount('Nina Ross', 'USD', 10_000);
        $dst = $this->createAccount('Owen Reed', 'USD', 0);
        $this->createTransfer($src['id'], $dst['id'], 1_000);

        $body = $this->getJson('/accounts/' . $src['id'] . '/transfers');

        self::assertSame(200, $this->getStatusCode());

        $data = $body['data'];
        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('total', $data);
        self::assertArrayHasKey('page', $data);
        self::assertArrayHasKey('perPage', $data);
        self::assertArrayHasKey('totalPages', $data);
        self::assertGreaterThanOrEqual(1, $data['total']);
        self::assertSame(1, $data['page']);
        self::assertNotEmpty($data['items']);
    }

    public function testGetAccountTransfersFiltersByStatus(): void
    {
        $src = $this->createAccount('Paul Young', 'USD', 10_000);
        $dst = $this->createAccount('Quinn Ward', 'USD', 0);
        $this->createTransfer($src['id'], $dst['id'], 1_000);

        $body = $this->getJson('/accounts/' . $src['id'] . '/transfers', ['status' => 'completed']);

        self::assertSame(200, $this->getStatusCode());
        foreach ($body['data']['items'] as $item) {
            self::assertSame('completed', $item['status']);
        }
    }

    public function testGetAccountTransfersReturns400OnInvalidStatus(): void
    {
        $account = $this->createAccount('Rita Fox', 'USD', 0);

        $body = $this->getJson('/accounts/' . $account['id'] . '/transfers', ['status' => 'invalid_value']);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('INVALID_STATUS', $body['error']['code']);
    }

    public function testGetAccountTransfersReturns400OnInvalidPerPage(): void
    {
        $account = $this->createAccount('Sam King', 'USD', 0);

        $body = $this->getJson('/accounts/' . $account['id'] . '/transfers', ['per_page' => 0]);

        self::assertSame(400, $this->getStatusCode());
        self::assertSame('INVALID_PER_PAGE', $body['error']['code']);
    }

    public function testGetAccountTransfersReturnsBothSourceAndDestinationTransfers(): void
    {
        $src = $this->createAccount('Tina Bell', 'USD', 10_000);
        $dst = $this->createAccount('Uma Scott', 'USD', 0);
        $this->createTransfer($src['id'], $dst['id'], 1_000);

        // Source account sees the transfer
        $srcBody = $this->getJson('/accounts/' . $src['id'] . '/transfers');
        self::assertSame(200, $this->getStatusCode());
        self::assertGreaterThanOrEqual(1, $srcBody['data']['total']);

        $srcIds = array_column($srcBody['data']['items'], 'id');

        // Destination account also sees the same transfer
        $dstBody = $this->getJson('/accounts/' . $dst['id'] . '/transfers');
        self::assertSame(200, $this->getStatusCode());
        self::assertGreaterThanOrEqual(1, $dstBody['data']['total']);

        $dstIds = array_column($dstBody['data']['items'], 'id');

        // The same transfer ID appears in both lists
        self::assertNotEmpty(array_intersect($srcIds, $dstIds));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /accounts — invalid JSON body
    // ──────────────────────────────────────────────────────────────────────────

    public function testCreateAccountReturns400OnInvalidJson(): void
    {
        $this->client->request(
            'POST',
            '/accounts',
            [],
            [],
            [
                'CONTENT_TYPE'            => 'application/json',
                'HTTP_X_IDEMPOTENCY_KEY'  => 'test-invalid-json-' . bin2hex(random_bytes(8)),
            ],
            'not-valid-json{{{',
        );

        self::assertSame(400, $this->getStatusCode());
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('INVALID_JSON', $body['error']['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /transfers — closed account edge cases (via Account state)
    // ──────────────────────────────────────────────────────────────────────────

    public function testTransferToClosedDestinationReturns409(): void
    {
        $src = $this->createAccount('Vera Long', 'USD', 5_000);
        $dst = $this->createAccount('Wayne Todd', 'USD', 0);

        // Close destination account
        $this->client->request('POST', '/accounts/' . $dst['id'] . '/close', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ]);
        self::assertSame(200, $this->getStatusCode());

        // Attempt transfer to closed account
        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
        ]);

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('ACCOUNT_CLOSED', $body['error']['code']);
    }

    public function testTransferFromClosedSourceReturns409(): void
    {
        $src = $this->createAccount('Xena Park', 'USD', 0);
        $dst = $this->createAccount('Yara Mills', 'USD', 0);

        // Close source account (zero balance so it can be closed)
        $this->client->request('POST', '/accounts/' . $src['id'] . '/close', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ]);
        self::assertSame(200, $this->getStatusCode());

        // Attempt transfer from closed account
        $body = $this->postJson('/transfers', [
            'sourceAccountId'      => $src['id'],
            'destinationAccountId' => $dst['id'],
            'amountMinorUnits'     => 1_000,
            'currency'             => 'USD',
        ]);

        self::assertSame(409, $this->getStatusCode());
        self::assertSame('ACCOUNT_CLOSED', $body['error']['code']);
    }
}
