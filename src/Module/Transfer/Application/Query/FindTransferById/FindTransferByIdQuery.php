<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Query\FindTransferById;

/**
 * Query: fetch a single Transfer by its ID.
 *
 * Plain value object — no framework dependencies.
 */
final readonly class FindTransferByIdQuery
{
    public function __construct(public readonly string $transferId) {}
}
