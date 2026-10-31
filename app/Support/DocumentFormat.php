<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Number and date formatting for printed documents, following Kosovo conventions (1.234,56 and 15.06.2026). */
class DocumentFormat
{
    public static function money(string|int|float|null $value, ?string $currency = null): string
    {
        $formatted = number_format((float) ($value ?? 0), 2, ',', '.');

        return $currency === null ? $formatted : "{$formatted} {$currency}";
    }

    public static function date(?string $value): string
    {
        return $value === null || $value === '' ? '—' : CarbonImmutable::parse(substr($value, 0, 10))->format('d.m.Y');
    }

    public static function quantity(string|int|float $value): string
    {
        $formatted = number_format((float) $value, 4, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    public static function rate(string|int|float|null $value): string
    {
        return self::quantity($value ?? 0).'%';
    }
}
