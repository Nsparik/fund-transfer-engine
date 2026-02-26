<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command\UnfreezeAccount;

/**
 * Command: reinstate a FROZEN account to ACTIVE status.
 */
final readonly class UnfreezeAccountCommand
{
    public function __construct(
        public readonly string $accountId,
    ) {}
}
