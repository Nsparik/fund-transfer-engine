<?php

declare(strict_types=1);

namespace App\Shared\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Custom Symfony constraint that validates a currency code against
 * the ISO 4217 allowlist.
 *
 * Usage:
 *   #[ValidCurrency]
 *   public string $currency;
 *
 * The regex-only guard (#[Regex('/^[A-Z]{3}$/')]) previously used would accept
 * fictional codes like "AAA", "ZZZ", etc.  This constraint rejects any code not
 * present in the published ISO 4217 list, preventing nonsensical accounts and
 * transfers from entering the system.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class ValidCurrency extends Constraint
{
    public string $message = 'The currency code "{{ value }}" is not a valid ISO 4217 currency code.';

    public function __construct(
        ?string $message = null,
        ?array  $groups  = null,
        mixed   $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);

        if ($message !== null) {
            $this->message = $message;
        }
    }
}
