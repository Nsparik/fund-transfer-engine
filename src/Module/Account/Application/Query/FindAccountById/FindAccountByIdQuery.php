<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Query\FindAccountById;

/**
 * Query: fetch a single Account by its ID.
 *
 * Plain value object — no framework dependencies.
 */
final readonly class FindAccountByIdQuery
{
    public function __construct(public readonly string $accountId) {}
}
