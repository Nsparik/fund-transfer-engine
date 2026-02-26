<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command\CloseAccount;

final readonly class CloseAccountCommand
{
    public function __construct(
        public readonly string $accountId,
    ) {}
}
