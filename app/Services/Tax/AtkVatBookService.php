<?php

namespace App\Services\Tax;

use App\Models\Business;
use App\Repositories\Contracts\FinancialReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Builds the monthly ATK sales book (Libri i Shitjes me TVSH) and purchase book (Libri i Blerjes me TVSH)
 * from issued invoices, following the 2024 ATK filling guides:
 * - a credit note or cancellation that lowers output VAT goes to purchase-book box 53/55, and one that lowers
 *   input VAT goes to sales-book box 16/18, always as a positive amount;
 * - reverse-charge purchases (box 65) also appear in sales-book box 28;
 * - an invoice issued and cancelled in the same month is left out of both books.
 */
class AtkVatBookService
{
    private const SALES_REDUCTION_BOX = ['12' => '53', '14' => '55'];

    private const PURCHASE_REDUCTION_BOX = ['35' => '16', '39' => '16', '43' => '16', '47' => '16', '37' => '18', '41' => '18', '45' => '18', '49' => '18', '51' => '18'];

    public function __construct(private FinancialReportRepository $financialReportRepository) {}

    /**
     * @return array{period: string, from: string, to: string, sales: array<string, mixed>, purchases: array<string, mixed>, vat_return: array<string, string>, problems: list<string>}
     */
    public function books(Business $business, string $period): array
    {
        if ($business->vat_status !== 'registered') {
            throw ValidationException::withMessages(['period' => ['The VAT sales and purchase books apply only to VAT-registered businesses.']]);
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $period);
        $from = $start->toDateString();
        $to = $start->endOfMonth()->toDateString();
        $lines = $this->financialReportRepository->vatLines($business, $from, $to);
        $cancelledWithinMonth = $lines
            ->filter(fn (object $line): bool => $line->entry_type === 'cancellation' && substr((string) $line->issued_on, 0, 10) >= $from)
            ->pluck('invoice_id')
            ->unique()
            ->all();
        $books = ['sales' => [], 'purchases' => []];
        $problems = [];

        foreach ($lines as $line) {
            if (in_array($line->invoice_id, $cancelledWithinMonth, true)) {
                continue;
            }

            $snapshot = json_decode((string) $line->tax_snapshot, true) ?: [];
            $treatment = (string) ($snapshot['treatment'] ?? '');
            $rate = (string) ($snapshot['rate_percent'] ?? '0');
            $salesBox = $snapshot['atk_sales_box'] ?? AtkVatBoxes::defaultSalesBox($treatment, $rate);
            $purchaseBox = $snapshot['atk_purchase_box'] ?? AtkVatBoxes::defaultPurchaseBox($treatment, $rate);
            $netCents = $this->toCents((string) $line->net_amount);
            $isReduction = $line->entry_type !== 'invoice';
            $box = $line->kind === 'sale' ? $salesBox : $purchaseBox;

            if ($box === null) {
                if ($treatment !== 'non_vat') {
                    $problems[] = "Invoice {$line->invoice_number}: VAT code {$line->vat_code} ({$treatment}, {$rate}%) has no ATK box. Add one to the VAT rules.";
                }

                continue;
            }

            if ($line->kind === 'sale') {
                if ($isReduction && isset(self::SALES_REDUCTION_BOX[$box])) {
                    $this->add($books, 'purchases', $line, self::SALES_REDUCTION_BOX[$box], $netCents);
                } else {
                    $this->add($books, 'sales', $line, $box, $isReduction ? -$netCents : $netCents);
                }

                continue;
            }

            if ($isReduction && isset(self::PURCHASE_REDUCTION_BOX[$box])) {
                $this->add($books, 'sales', $line, self::PURCHASE_REDUCTION_BOX[$box], $netCents);

                continue;
            }

            $signed = $isReduction ? -$netCents : $netCents;
            $this->add($books, 'purchases', $line, $box, $signed);

            if ($box === '65') {
                $this->add($books, 'sales', $line, '28', $signed);
            }
        }

        $sales = $this->finish($books['sales'], 'sales', $to, $problems);
        $purchases = $this->finish($books['purchases'], 'purchases', $to, $problems);
        $outputCents = $this->toCents($sales['totals']['30']);
        $inputCents = $this->toCents($purchases['totals']['67']);

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'sales' => $sales,
            'purchases' => $purchases,
            'vat_return' => [
                'output_vat' => $this->fromCents($outputCents),
                'input_vat' => $this->fromCents($inputCents),
                'difference' => $this->fromCents($outputCents - $inputCents),
            ],
            'problems' => array_values(array_unique($problems)),
        ];
    }

    /** @param array{sales: array<string, array<string, mixed>>, purchases: array<string, array<string, mixed>>} $books */
    private function add(array &$books, string $book, object $line, string $box, int $cents): void
    {
        $key = $line->invoice_id.'|'.$line->entry_type;
        $books[$book][$key] ??= [
            'date' => CarbonImmutable::parse((string) $line->reported_on)->format('d.m.Y'),
            'invoice_number' => (string) $line->invoice_number,
            'name' => (string) $line->counterparty_name,
            'nui' => $line->counterparty_nui === null ? '' : preg_replace('/\s+/', '', (string) $line->counterparty_nui),
            'is_foreign' => $line->counterparty_country !== null && strtoupper((string) $line->counterparty_country) !== 'XK',
            'cents' => [],
        ];
        $books[$book][$key]['cents'][$box] = ($books[$book][$key]['cents'][$box] ?? 0) + $cents;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<string>  $problems
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>, declared_with_zero: bool}
     */
    private function finish(array $rows, string $book, string $periodEnd, array &$problems): array
    {
        $columns = $book === 'sales' ? AtkVatBoxes::SALES_COLUMNS : AtkVatBoxes::PURCHASE_COLUMNS;
        $totals = array_fill_keys($columns, 0);
        $finished = [];

        foreach ($rows as $row) {
            $cents = $this->withCalculatedBoxes($book, $row['cents']);
            $identifier = $this->identifier($book, $row, $cents);

            if (! $this->identifierIsValid($book, $identifier)) {
                $problems[] = ($book === 'sales' ? 'Sales book' : 'Purchase book')." invoice {$row['invoice_number']}: {$row['name']} needs a NUI (81 + 7 digits) or fiscal number (6 + 8 digits)"
                    .($book === 'purchases' ? ', or a personal number (10 digits starting with 1 or 2)' : '').'. ATK rejects rows without one.';
            }

            foreach ($columns as $column) {
                $totals[$column] += $cents[$column] ?? 0;
            }

            $finished[] = [
                'date' => $row['date'],
                'invoice_number' => $row['invoice_number'],
                'name' => $row['name'],
                'identifier' => $identifier,
                'vat_number' => '',
                'boxes' => array_map(fn (int $value): string => $this->fromCents($value), array_intersect_key($cents + array_fill_keys($columns, 0), array_flip($columns))),
            ];
        }

        $declaredWithZero = $finished === [];

        if ($declaredWithZero) {
            // ATK: a book with no transactions is still declared, with 0 in the identification column.
            $finished[] = [
                'date' => CarbonImmutable::parse($periodEnd)->format('d.m.Y'),
                'invoice_number' => '0',
                'name' => '0',
                'identifier' => '0',
                'vat_number' => '',
                'boxes' => array_fill_keys($columns, '0.00'),
            ];
        }

        $totals = $this->withCalculatedBoxes($book, $totals);

        return [
            'rows' => $finished,
            'totals' => array_map(fn (int $value): string => $this->fromCents($value), array_intersect_key($totals, array_flip($columns))),
            'declared_with_zero' => $declaredWithZero,
        ];
    }

    /**
     * @param  array<string, int>  $cents
     * @return array<string, int>
     */
    private function withCalculatedBoxes(string $book, array $cents): array
    {
        $sum = fn (array $boxes): int => array_sum(array_map(fn (string $box): int => $cents[$box] ?? 0, $boxes));

        if ($book === 'sales') {
            $cents['10'] = $sum(['10a', '10b', '10c']);
            $cents['K1'] = $this->percentOf($sum(['12', '16', '20', '24', '28']), 18);
            $cents['K2'] = $this->percentOf($sum(['14', '18', '22', '26']), 8);
            $cents['30'] = $cents['K1'] + $cents['K2'];

            return $cents;
        }

        $cents['K1'] = $this->percentOf($sum(['35', '39', '43', '47', '53', '57', '61', '65']), 18);
        $cents['K2'] = $this->percentOf($sum(['37', '41', '45', '49', '51', '55', '59', '63']), 8);
        $cents['67'] = $cents['K1'] + $cents['K2'];

        return $cents;
    }

    /**
     * The identification column: the counterparty's NUI, fiscal or personal number, or an ATK code for
     * exports (3), services sold abroad (2), foreign reverse-charge purchases (6 in sales, 11 in purchases) and imports (8).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $cents
     */
    private function identifier(string $book, array $row, array $cents): string
    {
        $has = fn (string $box): bool => ($cents[$box] ?? 0) !== 0;

        if ($book === 'sales') {
            return match (true) {
                $has('11') => '3',
                $has('10a') => '2',
                $has('28') && $row['is_foreign'] => '6',
                default => $row['nui'],
            };
        }

        return match (true) {
            array_filter(AtkVatBoxes::IMPORT_BOXES, $has) !== [] => '8',
            $row['is_foreign'] => '11',
            default => $row['nui'],
        };
    }

    private function identifierIsValid(string $book, string $identifier): bool
    {
        if (preg_match('/^(81\d{7}|6\d{8})$/', $identifier) === 1) {
            return true;
        }

        return $book === 'sales'
            ? in_array($identifier, ['0', '1', '2', '3', '4', '5', '6', '7'], true)
            : in_array($identifier, ['0', '8', '9', '10', '11'], true) || preg_match('/^[12]\d{9}$/', $identifier) === 1;
    }

    private function percentOf(int $cents, int $rate): int
    {
        $value = abs($cents) * $rate;
        $rounded = intdiv($value + 50, 100);

        return $cents < 0 ? -$rounded : $rounded;
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $amount = abs($amount);

        return $sign.intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
