<?php

namespace App\Support;

use RuntimeException;

/**
 * Keyed hash of an encrypted value, so it can be compared for equality (duplicates, lookups)
 * without storing it in plain text. The business id is part of the hash, so the same person
 * has different hashes in different businesses.
 */
final class BlindIndex
{
    public static function personalNumber(int $businessId, ?string $personalNumber): ?string
    {
        $normalized = strtoupper((string) preg_replace('/\s+/', '', (string) $personalNumber));

        return $normalized === '' ? null : hash_hmac('sha256', "employee.personal_number|{$businessId}|{$normalized}", self::key());
    }

    private static function key(): string
    {
        $key = (string) (config('kontotrack.blind_index_key') ?: config('app.key'));

        if ($key === '') {
            throw new RuntimeException('Set APP_KEY (or KONTOTRACK_BLIND_INDEX_KEY) before storing employee data.');
        }

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}
