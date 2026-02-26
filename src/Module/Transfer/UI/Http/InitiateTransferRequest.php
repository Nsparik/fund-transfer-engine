<?php

declare(strict_types=1);

namespace App\Module\Transfer\UI\Http;

use App\Shared\Validator\Constraint\ValidCurrency;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * HTTP input DTO for POST /transfers.
 *
 * Hydrated from the raw JSON body via fromArray().
 * Validated by the Symfony Validator before the Application layer is touched.
 *
 * Expected JSON shape:
 * {
 *   "sourceAccountId":      "f47ac10b-58cc-4372-a567-0e02b2c3d479",
 *   "destinationAccountId": "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11",
 *   "amountMinorUnits":     9999,
 *   "currency":             "USD"
 * }
 */
final class InitiateTransferRequest
{
    #[NotBlank(message: 'sourceAccountId is required.')]
    #[Uuid(message: 'sourceAccountId must be a valid UUID.')]
    public string $sourceAccountId = '';

    #[NotBlank(message: 'destinationAccountId is required.')]
    #[Uuid(message: 'destinationAccountId must be a valid UUID.')]
    public string $destinationAccountId = '';

    /**
     * Must be an integer in the JSON body.
     * Floats (e.g. 9.99), strings, or null are rejected with a validation error.
     */
    #[NotNull(message: 'amountMinorUnits is required and must be an integer.')]
    #[GreaterThan(0, message: 'amountMinorUnits must be greater than 0.')]
    #[LessThanOrEqual(
        value: 999_999_999_99,
        message: 'amountMinorUnits must not exceed 99999999999 (the maximum single-transfer hard limit).',
    )]
    public ?int $amountMinorUnits = null;

    #[NotBlank(message: 'currency is required.')]
    #[ValidCurrency]
    public string $currency = '';

    /**
     * Optional payment narrative shown on statements and in audit records.
     * Trimmed whitespace; empty string is treated as absent (null).
     */
    #[Length(
        max: 500,
        maxMessage: 'description must not exceed 500 characters.',
    )]
    public ?string $description = null;

    public static function fromArray(array $data): self
    {
        $req = new self();

        $req->sourceAccountId      = (string) ($data['sourceAccountId'] ?? '');
        $req->destinationAccountId = (string) ($data['destinationAccountId'] ?? '');

        // Only accept native JSON integers; floats and strings → null → fails #[NotNull]
        $req->amountMinorUnits = isset($data['amountMinorUnits']) && is_int($data['amountMinorUnits'])
            ? $data['amountMinorUnits']
            : null;

        // Normalise to uppercase so "usd" → "USD" passes the regex constraint
        $req->currency = strtoupper((string) ($data['currency'] ?? ''));

        // Trim whitespace; treat empty string as absent
        $raw = isset($data['description']) ? trim((string) $data['description']) : null;
        $req->description = ($raw !== null && $raw !== '') ? $raw : null;

        return $req;
    }
}
