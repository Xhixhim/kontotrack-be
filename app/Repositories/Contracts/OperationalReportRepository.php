<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use Illuminate\Support\Collection;

interface OperationalReportRepository
{
    /**
     * Every active stock item with its movements summed before and inside the date range.
     *
     * @return Collection<int, object{id: int, sku: string|null, name: string, unit: string|null, purchase_price: string, minimum_quantity: string, opening_quantity: string, quantity_in: string, quantity_out: string}>
     */
    public function stockLevels(Business $business, ?int $warehouseId, string $from, string $to): Collection;

    /**
     * Lines of approved payroll runs whose month falls inside the range, oldest month first.
     *
     * @return Collection<int, object>
     */
    public function approvedPayrollLines(Business $business, string $from, string $to): Collection;

    /**
     * Fixed assets bought on or before the date, with the depreciation approved up to that month.
     *
     * @return Collection<int, object>
     */
    public function fixedAssetRegister(Business $business, ?string $category, string $asOf): Collection;
}
