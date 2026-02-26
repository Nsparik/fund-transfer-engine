<?php

declare(strict_types=1);

namespace App\Module\Account\UI\Http;

use Symfony\Component\Uid\Uuid;
use App\Shared\Validator\Constraint\ValidCurrency;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * HTTP input DTO for POST /accounts.
 *
 * Hydrated from the raw JSON body via fromArray().
 * Validated by the Symfony Validator before the Application layer is touched.
 *
 * Expected JSON shape:
 * {
 *   "accountId":               "f47ac10b-58cc-4372-a567-0e02b2c3d479",  (optional)
 *   "ownerName":               "Alice Smith",
 *   "currency":                "USD",
 *   "initialBalanceMinorUnits": 0
 * }
 *
 * The accountId MAY be supplied by the caller for idempotency purposes.
 * When provided alongside X-Idempotency-Key, a retry with the same key and
 * body will hit the idempotency cache and return the original response without
 * creating a duplicate account.
 * When absent, a server-generated UUIDv4 is used (backwards-compatible).
 */
final class CreateAccountRequest
{
    #[NotBlank(message: 'ownerName is required.')]
    #[Length(
        min: 1,
        max: 255,
        minMessage: 'ownerName must not be blank.',
        maxMessage: 'ownerName must not exceed 255 characters.',
    )]
    public string $ownerName = '';

    #[NotBlank(message: 'currency is required.')]
    #[ValidCurrency]
    public string $currency = '';

    /**
     * Opening balance in minor units.  Defaults to 0 (zero-balance account).
     * Must be ≥ 0 and ≤ 99,999,999,999 (≈ $1B cap to prevent fat-finger input).
     */
    #[GreaterThanOrEqual(
        value: 0,
        message: 'initialBalanceMinorUnits must be a non-negative integer (minor units, e.g. 1050 = $10.50).',
    )]
    #[LessThanOrEqual(
        value: 99_999_999_999,
        message: 'initialBalanceMinorUnits must not exceed 99,999,999,999 minor units.',
    )]
    public int $initialBalanceMinorUnits = 0;

    /**
     * UUID for the account.
     *
     * When supplied by the caller it is validated: an invalid UUID returns
     * HTTP 400 VALIDATION_ERROR rather than silently substituting a server-
     * generated UUID (the silent substitution meant a retry
     * with the same malformed accountId would generate a different UUID,
     * bypassing idempotency).
     *
     * When absent a server-generated UUIDv4 is used; server-generated values
     * are always valid and will always pass the regex constraint below.
     */
    #[Regex(
        pattern: '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
        message: 'accountId must be a valid UUID (v4 or v7 lowercase hex) when provided.',
    )]
    public readonly string $accountId;

    public static function fromArray(array $data): self
    {
        $req = new self();

        // A stable caller-supplied accountId ensures retries with the same
        // X-Idempotency-Key return the original response without creating a duplicate account.
        $suppliedId = isset($data['accountId']) ? strtolower(trim((string) $data['accountId'])) : '';
        $req->accountId = $suppliedId !== '' ? $suppliedId : (string) Uuid::v4();

        $req->ownerName = trim((string) ($data['ownerName'] ?? ''));

        // Normalise to uppercase so "usd" → "USD" passes the regex constraint
        $req->currency = strtoupper((string) ($data['currency'] ?? ''));

        // Only accept native JSON integers (PHP int type from json_decode).
        // Floats (e.g. 10.5) and strings are rejected by mapping to -1, which
        // fails the GreaterThanOrEqual(0) constraint with a clear validation
        // error rather than silently truncating to 0.
        if (isset($data['initialBalanceMinorUnits'])) {
            $raw = $data['initialBalanceMinorUnits'];
            $req->initialBalanceMinorUnits = is_int($raw) ? $raw : -1;
        }

        return $req;
    }
}
