<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Repositories\Contracts\FinancialReportRepository;
use App\Repositories\Contracts\OperationalReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reports built from the operational records rather than the ledger: turnover, stock, payroll and fixed assets.
 */
class OperationalReportService
{
    public const TYPES = ['turnover', 'stock', 'payroll', 'fixed_assets'];

    public function __construct(
        private OperationalReportRepository $operationalReportRepository,
        private FinancialReportRepository $financialReportRepository,
    ) {}

    /**
     * @param  array{counterparty_id?: int|null, warehouse_id?: int|null, category?: string|null, group_by?: string|null, business_unit_id?: int|null}  $options
     * @return array<string, mixed>
     */
    public function generate(Business $business, string $type, string $from, string $to, array $options = []): array
    {
        return match ($type) {
            'turnover' => $this->turnover($business, $from, $to, $options['counterparty_id'] ?? null, $options['group_by'] ?? 'month', $options['business_unit_id'] ?? null),
            'stock' => $this->stock($business, $from, $to, $options['warehouse_id'] ?? null),
            'payroll' => $this->payroll($business, $from, $to),
            'fixed_assets' => $this->fixedAssets($business, $to, $options['category'] ?? null),
        };
    }

    /** @return array<string, mixed> */
    private function turnover(Business $business, string $from, string $to, ?int $counterpartyId, string $groupBy, ?int $unitId): array
    {
        $counterparty = $counterpartyId === null ? null : $business->counterparties()->whereKey($counterpartyId)->first(['id', 'name']);

        if ($counterpartyId !== null && $counterparty === null) {
            throw ValidationException::withMessages(['counterparty_id' => ['Choose a customer or supplier of this business.']]);
        }

        $blank = ['sales_net' => 0, 'sales_tax' => 0, 'sales_gross' => 0, 'purchases_net' => 0, 'purchases_tax' => 0, 'purchases_gross' => 0];
        $groups = [];

        foreach ($this->financialReportRepository->vatLines($business, $from, $to) as $line) {
            if ($counterpartyId !== null && (int) $line->counterparty_id !== $counterpartyId) {
                continue;
            }

            if ($unitId !== null && (int) $line->business_unit_id !== $unitId) {
                continue;
            }

            [$key, $label] = $groupBy === 'counterparty'
                ? ['c'.$line->counterparty_id, (string) $line->counterparty_name]
                : [substr($line->reported_on, 0, 7), substr($line->reported_on, 5, 2).'.'.substr($line->reported_on, 0, 4)];
            $groups[$key] ??= ['label' => $label, ...$blank];
            $side = $line->kind === 'sale' ? 'sales' : 'purchases';

            foreach (['net', 'tax', 'gross'] as $part) {
                $groups[$key]["{$side}_{$part}"] += (int) $line->sign * $this->toCents((string) $line->{"{$part}_amount"});
            }
        }

        if ($groupBy === 'counterparty') {
            uasort($groups, fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));
        } else {
            ksort($groups);
        }

        $totals = $blank;

        foreach ($groups as $group) {
            foreach (array_keys($blank) as $column) {
                $totals[$column] += $group[$column];
            }
        }

        $format = fn (array $row): array => array_map(fn (mixed $value): mixed => is_int($value) ? $this->fromCents($value) : $value, $row);

        return [
            'type' => 'turnover',
            'from' => $from,
            'to' => $to,
            'currency' => $business->currency,
            'group_by' => $groupBy,
            'counterparty' => $counterparty === null ? null : ['id' => $counterparty->id, 'name' => $counterparty->name],
            'business_unit' => $unitId === null ? null : $business->units()->whereKey($unitId)->first(['id', 'name'])?->only(['id', 'name']),
            'rows' => array_map($format, array_values($groups)),
            'totals' => $format($totals),
        ];
    }

    /** @return array<string, mixed> */
    private function stock(Business $business, string $from, string $to, ?int $warehouseId): array
    {
        $warehouse = $warehouseId === null ? null : $business->warehouses()->whereKey($warehouseId)->first(['id', 'name']);

        if ($warehouseId !== null && $warehouse === null) {
            throw ValidationException::withMessages(['warehouse_id' => ['Choose a warehouse of this business.']]);
        }

        $rows = [];
        $totalValue = '0.00';

        foreach ($this->operationalReportRepository->stockLevels($business, $warehouseId, $from, $to) as $item) {
            $opening = bcadd((string) $item->opening_quantity, '0', 4);
            $in = bcadd((string) $item->quantity_in, '0', 4);
            $out = bcadd((string) $item->quantity_out, '0', 4);
            $closing = bcsub(bcadd($opening, $in, 4), $out, 4);
            $value = bcadd(bcmul($closing, (string) $item->purchase_price, 6), bccomp($closing, '0', 4) < 0 ? '-0.005' : '0.005', 2);
            $totalValue = bcadd($totalValue, $value, 2);
            $rows[] = [
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit,
                'opening_quantity' => $this->quantity($opening),
                'quantity_in' => $this->quantity($in),
                'quantity_out' => $this->quantity($out),
                'closing_quantity' => $this->quantity($closing),
                'minimum_quantity' => $this->quantity((string) $item->minimum_quantity),
                // A minimum only applies to the business as a whole, not to one warehouse.
                'below_minimum' => $warehouseId === null && bccomp((string) $item->minimum_quantity, '0', 4) > 0 && bccomp($closing, (string) $item->minimum_quantity, 4) < 0,
                'unit_cost' => bcadd((string) $item->purchase_price, '0', 4),
                'value' => $value,
            ];
        }

        return [
            'type' => 'stock',
            'from' => $from,
            'to' => $to,
            'currency' => $business->currency,
            'warehouse' => $warehouse === null ? null : ['id' => $warehouse->id, 'name' => $warehouse->name],
            'rows' => $rows,
            'totals' => ['value' => $totalValue],
        ];
    }

    /** @return array<string, mixed> */
    private function payroll(Business $business, string $from, string $to): array
    {
        $columns = ['gross_salary', 'employee_contribution', 'employer_contribution', 'income_tax', 'net_salary'];
        $employees = [];
        $months = [];
        $totals = array_fill_keys($columns, 0);

        foreach ($this->operationalReportRepository->approvedPayrollLines($business, $from, $to) as $line) {
            $month = sprintf('%04d-%02d', $line->period_year, $line->period_month);
            $months[$month] = true;
            $key = $line->employee_id ?? 'n'.$line->employee_name;
            $employees[$key] ??= ['employee_name' => (string) $line->employee_name, 'months' => 0, ...array_fill_keys($columns, 0)];
            $employees[$key]['months']++;

            foreach ($columns as $column) {
                $cents = $this->toCents((string) $line->{$column});
                $employees[$key][$column] += $cents;
                $totals[$column] += $cents;
            }
        }

        uasort($employees, fn (array $left, array $right): int => strcasecmp($left['employee_name'], $right['employee_name']));
        $format = fn (array $row): array => [...$row, ...array_map(fn (string $column): string => $this->fromCents($row[$column]), array_combine($columns, $columns))];

        return [
            'type' => 'payroll',
            'from' => $from,
            'to' => $to,
            'currency' => $business->currency,
            'months' => array_keys($months),
            'rows' => array_map($format, array_values($employees)),
            'totals' => $format($totals),
        ];
    }

    /** @return array<string, mixed> */
    private function fixedAssets(Business $business, string $asOf, ?string $category): array
    {
        $rows = [];
        $totals = ['cost' => 0, 'accumulated_depreciation' => 0, 'book_value' => 0];

        foreach ($this->operationalReportRepository->fixedAssetRegister($business, $category, $asOf) as $asset) {
            $isDisposed = $asset->disposed_on !== null && CarbonImmutable::parse($asset->disposed_on)->toDateString() <= $asOf;
            $cost = $this->toCents((string) $asset->cost);
            $accumulated = min($cost, $this->toCents((string) $asset->opening_accumulated_depreciation) + $this->toCents((string) $asset->depreciated_amount));
            // A disposed asset has left the books: it is listed for the record but adds nothing to the totals.
            $bookValue = $isDisposed ? 0 : $cost - $accumulated;
            $rows[] = [
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'category' => $asset->category,
                'location' => $asset->location,
                'acquired_on' => CarbonImmutable::parse($asset->acquired_on)->toDateString(),
                'disposed_on' => $isDisposed ? CarbonImmutable::parse($asset->disposed_on)->toDateString() : null,
                'cost' => $this->fromCents($cost),
                'accumulated_depreciation' => $this->fromCents($accumulated),
                'book_value' => $this->fromCents($bookValue),
            ];

            if (! $isDisposed) {
                $totals['cost'] += $cost;
                $totals['accumulated_depreciation'] += $accumulated;
                $totals['book_value'] += $bookValue;
            }
        }

        return [
            'type' => 'fixed_assets',
            'as_of' => $asOf,
            'currency' => $business->currency,
            'category' => $category,
            'rows' => $rows,
            'totals' => array_map(fn (int $cents): string => $this->fromCents($cents), $totals),
        ];
    }

    /** Quantities are stored with four decimals; show only the ones that are used. */
    private function quantity(string $quantity): string
    {
        $trimmed = rtrim(rtrim(bcadd($quantity, '0', 4), '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
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
