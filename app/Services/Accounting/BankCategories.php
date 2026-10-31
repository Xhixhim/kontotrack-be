<?php

namespace App\Services\Accounting;

/**
 * What a bank receipt or payment is for. Customer receipts and supplier payments are booked
 * against invoices (accounts receivable / payable); every other category against a chosen account.
 */
final class BankCategories
{
    /** @var array<string, array{direction: 'in'|'out'|'any', needs: 'counterparty'|'account', label: string}> */
    public const ALL = [
        'customer_receipt' => ['direction' => 'in', 'needs' => 'counterparty', 'label' => 'Arkëtim nga klienti · Customer receipt'],
        'supplier_payment' => ['direction' => 'out', 'needs' => 'counterparty', 'label' => 'Pagesë furnitorit · Supplier payment'],
        'salary' => ['direction' => 'out', 'needs' => 'account', 'label' => 'Paga · Salaries'],
        'tax' => ['direction' => 'out', 'needs' => 'account', 'label' => 'Tatime dhe kontribute · Taxes and contributions'],
        'bank_fee' => ['direction' => 'out', 'needs' => 'account', 'label' => 'Komisione bankare · Bank fees'],
        'loan' => ['direction' => 'any', 'needs' => 'account', 'label' => 'Kredi · Loans'],
        'owner' => ['direction' => 'any', 'needs' => 'account', 'label' => 'Pronari · Owner capital or drawings'],
        'transfer' => ['direction' => 'any', 'needs' => 'account', 'label' => 'Transfer mes llogarive · Transfer between own accounts'],
        'other_income' => ['direction' => 'in', 'needs' => 'account', 'label' => 'Të hyra të tjera · Other income'],
        'other_expense' => ['direction' => 'out', 'needs' => 'account', 'label' => 'Shpenzime të tjera · Other expenses'],
    ];

    public static function fitsDirection(string $category, string $amount): bool
    {
        $direction = self::ALL[$category]['direction'] ?? null;

        return $direction === 'any' || $direction === (str_starts_with($amount, '-') ? 'out' : 'in');
    }

    /** Lowercase text without Albanian diacritics, so "Komision" matches "KOMISIONË". */
    public static function normalize(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['ë' => 'e', 'ç' => 'c']);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
