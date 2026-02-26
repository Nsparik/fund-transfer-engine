<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command\CreateAccount;

/**
 * Command: request to open a new Account.
 *
 * Plain value object — no framework dependencies.
 * Created by the UI layer and passed to CreateAccountHandler.
 */
final readonly class CreateAccountCommand
{
    /**
     * @param string $accountId             UUID for the new account (caller-supplied for idempotency)
     * @param string $ownerName             Human-readable account holder name
     * @param string $currency              3-char uppercase ISO 4217 code, e.g. "USD"
     * @param int    $initialBalanceMinorUnits  Opening balance in minor units (≥ 0, default 0)
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $ownerName,
        public readonly string $currency,
        public readonly int    $initialBalanceMinorUnits = 0,
    ) {}
}
