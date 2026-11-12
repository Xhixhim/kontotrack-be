<?php

namespace App\Repositories\Contracts;

use App\Models\Business;

interface BusinessDashboardRepository
{
    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     revenue: string,
     *     expenses: string,
     *     result: string,
     *     sales_vat: string,
     *     purchase_vat: string,
     *     net_vat: string,
     *     cash_balance: string,
     *     bank_balance: string,
     *     receivables: string,
     *     overdue_receivables: string,
     *     receivable_invoices: int,
     *     payables: string,
     *     overdue_payables: string,
     *     payable_invoices: int,
     *     payroll_gross: string,
     *     payroll_employees: int,
     *     payroll_status: string|null,
     *     stock_value: string,
     *     stock_items: int
     * }
     */
    public function summaryForBusiness(Business $business, string $from, string $to, ?int $unitId = null): array;
}
