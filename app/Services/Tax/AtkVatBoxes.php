<?php

namespace App\Services\Tax;

/**
 * Boxes of the Kosovo VAT return (Deklarata e TVSH-së) as used by the ATK sales and purchase books.
 * Source: ATK guides "Udhëzues për plotësimin e LIBRIT TË SHITJES / TË BLERJES me TVSH" (2024).
 */
class AtkVatBoxes
{
    /**
     * Sales-book boxes a VAT code may point to, with the rate a code using it must have (null = not taxable).
     *
     * @var array<string, int|null>
     */
    public const SALES_INPUT = ['9' => null, '10a' => null, '10b' => null, '10c' => null, '11' => null, '12' => 18, '14' => 8];

    /**
     * Purchase-book boxes a VAT code may point to, with the rate a code using it must have (null = not taxable,
     * 'any' = taxable at any rate because the VAT is not deductible).
     *
     * @var array<string, int|string|null>
     */
    public const PURCHASE_INPUT = [
        '31' => null, '32' => null, '33' => 'any', '34' => 'any',
        '35' => 18, '39' => 18, '43' => 18, '47' => 18,
        '37' => 8, '41' => 8, '45' => 8, '49' => 8, '51' => 'any',
        '65' => null,
    ];

    /** Purchase boxes for goods imported through customs; their rows use identifier 8. */
    public const IMPORT_BOXES = ['35', '37', '39', '41'];

    /** Sales-book column order after the five identification columns, as in the ATK template "Libri i Shitjes". */
    public const SALES_COLUMNS = ['9', '10a', '10b', '10c', '10', '11', '12', '16', '20', '24', '28', 'K1', '14', '18', '22', '26', 'K2', '30'];

    /** Purchase-book column order after the five identification columns, as in the ATK template "Libri i Blerjes". */
    public const PURCHASE_COLUMNS = ['31', '32', '33', '34', '35', '39', '43', '47', '53', '57', '61', '65', 'K1', '37', '41', '45', '49', '51', '55', '59', '63', 'K2', '67'];

    public static function defaultSalesBox(string $treatment, string $ratePercent): ?string
    {
        return match (true) {
            $treatment === 'taxable' && (float) $ratePercent === 18.0 => '12',
            $treatment === 'taxable' && (float) $ratePercent === 8.0 => '14',
            $treatment === 'zero_rated' => '11',
            $treatment === 'exempt' => '9',
            default => null,
        };
    }

    public static function defaultPurchaseBox(string $treatment, string $ratePercent): ?string
    {
        return match (true) {
            $treatment === 'taxable' && (float) $ratePercent === 18.0 => '43',
            $treatment === 'taxable' && (float) $ratePercent === 8.0 => '45',
            in_array($treatment, ['zero_rated', 'exempt'], true) => '31',
            default => null,
        };
    }

    /**
     * Problems with the ATK boxes of one VAT code, or an empty list when the boxes fit its treatment and rate.
     *
     * @param  array<string, mixed>  $code
     * @return list<string>
     */
    public static function problems(array $code): array
    {
        $problems = [];
        $isTaxable = ($code['treatment'] ?? null) === 'taxable';
        $rate = (float) ($code['rate_percent'] ?? 0);

        foreach (['atk_sales_box' => self::SALES_INPUT, 'atk_purchase_box' => self::PURCHASE_INPUT] as $key => $allowed) {
            if (! array_key_exists($key, $code) || $code[$key] === null) {
                continue;
            }

            $box = (string) $code[$key];

            if (! array_key_exists($box, $allowed)) {
                $problems[] = "VAT code {$code['code']}: {$key} must be one of ".implode(', ', array_keys($allowed)).'.';

                continue;
            }

            $requiredRate = $allowed[$box];
            $fits = match (true) {
                $requiredRate === null => ! $isTaxable,
                $requiredRate === 'any' => $isTaxable,
                default => $isTaxable && $rate === (float) $requiredRate,
            };

            if (! $fits) {
                $problems[] = "VAT code {$code['code']}: box {$box} does not fit a {$code['treatment']} code at {$code['rate_percent']}%.";
            }
        }

        return $problems;
    }
}
