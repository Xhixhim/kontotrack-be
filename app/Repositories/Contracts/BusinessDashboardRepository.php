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
     *     payable_invoices: int
     * }
     */
    public function summaryForBusiness(Business $business, string $from, string $to): array;
}
