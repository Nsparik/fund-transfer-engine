<?php

declare(strict_types=1);

namespace App\Module\Transfer\UI\Http;

/**
 * HTTP input DTO for POST /transfers/{id}/reverse.
 *
 * Currently carries no body fields — the transfer ID is taken from the URL path.
 * Kept as an explicit class so that a future "reason" or "operator notes" field
 * can be added without touching the controller signature.
 */
final class ReverseTransferRequest
{
    /**
     * @param string $transferId UUID extracted from the route path
     */
    public function __construct(
        public readonly string $transferId,
    ) {}
}
