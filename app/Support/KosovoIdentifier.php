<?php

namespace App\Support;

/**
 * Formats of the identifiers used on Kosovo invoices and tax books.
 *
 * - NUI (unique identification number from the business register) and the fiscal number: 9 digits.
 * - Personal number, used for individuals: 10 digits.
 *
 * Only the format is checked; whether the number exists can only be answered by the official registers.
 */
class KosovoIdentifier
{
    public const BUSINESS_PATTERN = '/^\d{9}$/';

    public static function isBusinessNumber(string $value): bool
    {
        return preg_match(self::BUSINESS_PATTERN, $value) === 1;
    }

    /**
     * A Kosovo customer or supplier is a business (9 digits) or an individual (10 digits).
     * Foreign identifiers follow other countries' rules, so only obviously broken values are refused.
     */
    public static function isValidForCounterparty(string $value, string $countryCode): bool
    {
        return strtoupper($countryCode) === 'XK'
            ? preg_match('/^(\d{9}|\d{10})$/', $value) === 1
            : preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/-]{1,31}$/', $value) === 1;
    }
}
