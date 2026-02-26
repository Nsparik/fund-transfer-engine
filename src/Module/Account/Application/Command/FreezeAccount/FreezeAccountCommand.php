<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command\FreezeAccount;

/**
 * Command: request to freeze an existing Account.
 *
 * Plain value object — no framework dependencies.
 */
final readonly class FreezeAccountCommand
{
    /**
     * @param string $accountId UUID of the account to freeze
     */
    public function __construct(
        public readonly string $accountId,
    ) {}
}
