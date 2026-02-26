<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Command\InitiateTransfer;

/**
 * Command: request to initiate a new fund transfer.
 *
 * This is a plain value object — no framework dependencies.
 * It is created by the UI layer (HTTP controller or CLI command) and
 * passed to InitiateTransferHandler.
 *
 * Amount is expressed in minor units (cents) to avoid floating-point
 * issues.  Currency must be a 3-character ISO 4217 code (e.g. "USD").
 */
final readonly class InitiateTransferCommand
{
    /**
     * @param string  $sourceAccountId      UUID of the debiting account
     * @param string  $destinationAccountId UUID of the crediting account
     * @param int     $amountMinorUnits      Amount in minor units (must be > 0)
     * @param string  $currency             ISO 4217 code, e.g. "USD"
     * @param ?string $description          Optional payment narrative (max 500 chars)
     * @param ?string $idempotencyKey       Client-supplied X-Idempotency-Key; written
     *                                      atomically with the transfer commit so that
     *                                      crash-after-commit retries are deduplicated
     *                                      at the DB layer, not just the HTTP cache.
     */
    public function __construct(
        public readonly string  $sourceAccountId,
        public readonly string  $destinationAccountId,
        public readonly int     $amountMinorUnits,
        public readonly string  $currency,
        public readonly ?string $description    = null,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
