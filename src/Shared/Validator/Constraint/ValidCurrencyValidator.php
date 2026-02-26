<?php

declare(strict_types=1);

namespace App\Shared\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validator for the {@see ValidCurrency} constraint.
 *
 * Checks the submitted value against the complete ISO 4217 active currency
 * codes list (alphabetic codes, A–Z, 3-letter, as published by the ISO).
 *
 * Null values are considered valid (combine with #[NotBlank] / #[NotNull] to
 * enforce presence separately).
 */
final class ValidCurrencyValidator extends ConstraintValidator
{
    /**
     * Complete list of ISO 4217 active alphabetic currency codes.
     * Updated to the 2024 revision of the standard.
     *
     * @var array<string, true>
     */
    private const VALID_CODES = [
        'AED' => true, 'AFN' => true, 'ALL' => true, 'AMD' => true,
        'ANG' => true, 'AOA' => true, 'ARS' => true, 'AUD' => true,
        'AWG' => true, 'AZN' => true, 'BAM' => true, 'BBD' => true,
        'BDT' => true, 'BGN' => true, 'BHD' => true, 'BIF' => true,
        'BMD' => true, 'BND' => true, 'BOB' => true, 'BOV' => true,
        'BRL' => true, 'BSD' => true, 'BTN' => true, 'BWP' => true,
        'BYN' => true, 'BZD' => true, 'CAD' => true, 'CDF' => true,
        'CHE' => true, 'CHF' => true, 'CHW' => true, 'CLF' => true,
        'CLP' => true, 'CNY' => true, 'COP' => true, 'COU' => true,
        'CRC' => true, 'CUP' => true, 'CVE' => true, 'CZK' => true,
        'DJF' => true, 'DKK' => true, 'DOP' => true, 'DZD' => true,
        'EGP' => true, 'ERN' => true, 'ETB' => true, 'EUR' => true,
        'FJD' => true, 'FKP' => true, 'GBP' => true, 'GEL' => true,
        'GHS' => true, 'GIP' => true, 'GMD' => true, 'GNF' => true,
        'GTQ' => true, 'GYD' => true, 'HKD' => true, 'HNL' => true,
        'HTG' => true, 'HUF' => true, 'IDR' => true, 'ILS' => true,
        'INR' => true, 'IQD' => true, 'IRR' => true, 'ISK' => true,
        'JMD' => true, 'JOD' => true, 'JPY' => true, 'KES' => true,
        'KGS' => true, 'KHR' => true, 'KMF' => true, 'KPW' => true,
        'KRW' => true, 'KWD' => true, 'KYD' => true, 'KZT' => true,
        'LAK' => true, 'LBP' => true, 'LKR' => true, 'LRD' => true,
        'LSL' => true, 'LYD' => true, 'MAD' => true, 'MDL' => true,
        'MGA' => true, 'MKD' => true, 'MMK' => true, 'MNT' => true,
        'MOP' => true, 'MRU' => true, 'MUR' => true, 'MVR' => true,
        'MWK' => true, 'MXN' => true, 'MXV' => true, 'MYR' => true,
        'MZN' => true, 'NAD' => true, 'NGN' => true, 'NIO' => true,
        'NOK' => true, 'NPR' => true, 'NZD' => true, 'OMR' => true,
        'PAB' => true, 'PEN' => true, 'PGK' => true, 'PHP' => true,
        'PKR' => true, 'PLN' => true, 'PYG' => true, 'QAR' => true,
        'RON' => true, 'RSD' => true, 'RUB' => true, 'RWF' => true,
        'SAR' => true, 'SBD' => true, 'SCR' => true, 'SDG' => true,
        'SEK' => true, 'SGD' => true, 'SHP' => true, 'SLE' => true,
        'SLL' => true, 'SOS' => true, 'SRD' => true, 'SSP' => true,
        'STN' => true, 'SVC' => true, 'SYP' => true, 'SZL' => true,
        'THB' => true, 'TJS' => true, 'TMT' => true, 'TND' => true,
        'TOP' => true, 'TRY' => true, 'TTD' => true, 'TWD' => true,
        'TZS' => true, 'UAH' => true, 'UGX' => true, 'USD' => true,
        'USN' => true, 'UYI' => true, 'UYU' => true, 'UYW' => true,
        'UZS' => true, 'VED' => true, 'VES' => true, 'VND' => true,
        'VUV' => true, 'WST' => true, 'XAF' => true, 'XAG' => true,
        'XAU' => true, 'XBA' => true, 'XBB' => true, 'XBC' => true,
        'XBD' => true, 'XCD' => true, 'XDR' => true, 'XOF' => true,
        'XPD' => true, 'XPF' => true, 'XPT' => true, 'XSU' => true,
        'XTS' => true, 'XUA' => true, 'XXX' => true, 'YER' => true,
        'ZAR' => true, 'ZMW' => true, 'ZWG' => true, 'ZWL' => true,
    ];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCurrency) {
            throw new UnexpectedTypeException($constraint, ValidCurrency::class);
        }

        // null / empty string: let NotBlank/NotNull handle those
        if ($value === null || $value === '') {
            return;
        }

        if (!isset(self::VALID_CODES[(string) $value])) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->addViolation();
        }
    }
}
