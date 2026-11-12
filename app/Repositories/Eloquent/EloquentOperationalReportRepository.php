<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Repositories\Contracts\OperationalReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentOperationalReportRepository implements OperationalReportRepository
{
    public function stockLevels(Business $business, ?int $warehouseId, string $from, string $to): Collection
    {
        $businessId = (int) $business->getKey();
        $movements = DB::table('stock_movements')
            ->where('business_id', $businessId)
            ->whereDate('occurred_on', '<=', $to)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('inventory_item_id')
            ->select('inventory_item_id')
            ->selectRaw('SUM(CASE WHEN occurred_on < ? THEN quantity_delta ELSE 0 END) AS opening_quantity', [$from])
            ->selectRaw('SUM(CASE WHEN occurred_on >= ? AND quantity_delta > 0 THEN quantity_delta ELSE 0 END) AS quantity_in', [$from])
            ->selectRaw('SUM(CASE WHEN occurred_on >= ? AND quantity_delta < 0 THEN -quantity_delta ELSE 0 END) AS quantity_out', [$from]);

        return DB::table('inventory_items')
            ->leftJoinSub($movements, 'movements', 'movements.inventory_item_id', '=', 'inventory_items.id')
            ->where('inventory_items.business_id', $businessId)
            ->where(fn ($query) => $query->where('inventory_items.is_active', true)->orWhereNotNull('movements.inventory_item_id'))
            ->orderBy('inventory_items.name')
            ->get([
                'inventory_items.id',
                'inventory_items.sku',
                'inventory_items.name',
                'inventory_items.unit',
                'inventory_items.purchase_price',
                'inventory_items.minimum_quantity',
                DB::raw('COALESCE(movements.opening_quantity, 0) AS opening_quantity'),
                DB::raw('COALESCE(movements.quantity_in, 0) AS quantity_in'),
                DB::raw('COALESCE(movements.quantity_out, 0) AS quantity_out'),
            ]);
    }

    public function approvedPayrollLines(Business $business, string $from, string $to): Collection
    {
        $fromMonth = $this->monthNumber($from);
        $toMonth = $this->monthNumber($to);

        return DB::table('payroll_lines')
            ->join('payroll_runs', function ($join): void {
                $join->on('payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
                    ->on('payroll_runs.business_id', '=', 'payroll_lines.business_id');
            })
            ->where('payroll_lines.business_id', (int) $business->getKey())
            ->where('payroll_runs.status', 'approved')
            ->whereRaw('(payroll_runs.period_year * 100 + payroll_runs.period_month) BETWEEN ? AND ?', [$fromMonth, $toMonth])
            ->orderBy('payroll_runs.period_year')
            ->orderBy('payroll_runs.period_month')
            ->orderBy('payroll_lines.employee_name')
            ->get([
                'payroll_runs.period_year',
                'payroll_runs.period_month',
                'payroll_lines.employee_id',
                'payroll_lines.employee_name',
                'payroll_lines.gross_salary',
                'payroll_lines.employee_contribution',
                'payroll_lines.employer_contribution',
                'payroll_lines.income_tax',
                'payroll_lines.net_salary',
            ]);
    }

    public function fixedAssetRegister(Business $business, ?string $category, string $asOf): Collection
    {
        $businessId = (int) $business->getKey();
        $depreciation = DB::table('depreciation_lines')
            ->join('depreciation_runs', function ($join): void {
                $join->on('depreciation_runs.id', '=', 'depreciation_lines.depreciation_run_id')
                    ->on('depreciation_runs.business_id', '=', 'depreciation_lines.business_id');
            })
            ->where('depreciation_lines.business_id', $businessId)
            ->where('depreciation_runs.status', 'approved')
            ->whereRaw('(depreciation_runs.period_year * 100 + depreciation_runs.period_month) <= ?', [$this->monthNumber($asOf)])
            ->groupBy('depreciation_lines.fixed_asset_id')
            ->select('depreciation_lines.fixed_asset_id')
            ->selectRaw('SUM(depreciation_lines.amount) AS depreciated_amount');

        return DB::table('fixed_assets')
            ->leftJoinSub($depreciation, 'depreciation', 'depreciation.fixed_asset_id', '=', 'fixed_assets.id')
            ->where('fixed_assets.business_id', $businessId)
            ->whereDate('fixed_assets.acquired_on', '<=', $asOf)
            ->when($category !== null, fn ($query) => $query->where('fixed_assets.category', $category))
            ->orderBy('fixed_assets.category')
            ->orderBy('fixed_assets.asset_code')
            ->get([
                'fixed_assets.asset_code',
                'fixed_assets.name',
                'fixed_assets.category',
                'fixed_assets.location',
                'fixed_assets.acquired_on',
                'fixed_assets.cost',
                'fixed_assets.opening_accumulated_depreciation',
                'fixed_assets.disposed_on',
                DB::raw('COALESCE(depreciation.depreciated_amount, 0) AS depreciated_amount'),
            ]);
    }

    private function monthNumber(string $date): int
    {
        $parsed = CarbonImmutable::parse($date);

        return $parsed->year * 100 + $parsed->month;
    }
}
