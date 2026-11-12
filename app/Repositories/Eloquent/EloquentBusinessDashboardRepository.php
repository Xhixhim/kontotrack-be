<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Repositories\Contracts\BusinessDashboardRepository;
use Illuminate\Support\Facades\DB;

class EloquentBusinessDashboardRepository implements BusinessDashboardRepository
{
    public function summaryForBusiness(Business $business, string $from, string $to, ?int $unitId = null): array
    {
        $businessId = (int) $business->getKey();
        $periodAmounts = DB::table('journal_lines as lines')
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->on('entries.business_id', '=', 'lines.business_id');
            })
            ->join('accounts', function ($join) use ($businessId): void {
                $join->on('accounts.id', '=', 'lines.account_id')
                    ->on('accounts.business_id', '=', 'lines.business_id')
                    ->where('accounts.business_id', '=', $businessId);
            })
            ->where('entries.business_id', $businessId)
            ->where('entries.status', 'posted')
            ->whereBetween('entries.entry_date', [$from, $to])
            ->when($unitId !== null, fn ($query) => $query->where('entries.business_unit_id', $unitId))
            ->whereIn('accounts.account_type', ['revenue', 'expense'])
            ->selectRaw('accounts.account_type, SUM(CASE WHEN accounts.account_type = ? THEN lines.credit - lines.debit ELSE lines.debit - lines.credit END) AS total', ['revenue'])
            ->groupBy('accounts.account_type')
            ->pluck('total', 'account_type');

        $revenueCents = $this->toCents((string) ($periodAmounts['revenue'] ?? '0'));
        $expenseCents = $this->toCents((string) ($periodAmounts['expense'] ?? '0'));
        $salesTaxCents = 0;
        $purchaseTaxCents = 0;
        $invoiceTaxes = DB::table('invoices')
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'invoices.journal_entry_id')
                    ->on('entries.business_id', '=', 'invoices.business_id');
            })
            ->where('invoices.business_id', $businessId)
            ->where('entries.status', 'posted')
            ->whereBetween('invoices.issued_on', [$from, $to])
            ->when($unitId !== null, fn ($query) => $query->where('invoices.business_unit_id', $unitId))
            ->whereIn('invoices.status', ['issued', 'partially_paid', 'paid', 'cancelled'])
            ->selectRaw("invoices.kind, SUM(CASE WHEN invoices.document_type = 'credit_note' THEN -invoices.tax_amount ELSE invoices.tax_amount END) AS total")
            ->groupBy('invoices.kind')
            ->pluck('total', 'kind');
        $cancelledInvoiceTaxes = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('status', 'cancelled')
            ->whereBetween('cancelled_on', [$from, $to])
            ->when($unitId !== null, fn ($query) => $query->where('business_unit_id', $unitId))
            ->selectRaw('kind, SUM(tax_amount) AS total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $salesTaxCents = $this->toCents((string) ($invoiceTaxes['sale'] ?? '0')) - $this->toCents((string) ($cancelledInvoiceTaxes['sale'] ?? '0'));
        $purchaseTaxCents = $this->toCents((string) ($invoiceTaxes['purchase'] ?? '0')) - $this->toCents((string) ($cancelledInvoiceTaxes['purchase'] ?? '0'));

        $cashAccountIds = $business->cashAccounts()->where('is_active', true)->pluck('account_id')->map(fn ($id): int => (int) $id)->all();
        $bankAccountIds = $business->bankAccounts()->where('is_active', true)->pluck('account_id')->map(fn ($id): int => (int) $id)->all();

        $allocatedPayments = DB::table('invoice_payment')
            ->join('payments', function ($join): void {
                $join->on('payments.id', '=', 'invoice_payment.payment_id')
                    ->on('payments.business_id', '=', 'invoice_payment.business_id');
            })
            ->where('invoice_payment.business_id', $businessId)
            ->whereDate('payments.paid_on', '<=', $to)
            ->select(['invoice_payment.invoice_id', 'invoice_payment.business_id'])
            ->selectRaw('SUM(invoice_payment.amount) AS paid_amount')
            ->groupBy('invoice_payment.invoice_id', 'invoice_payment.business_id');
        $credits = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('document_type', 'credit_note')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereDate('issued_on', '<=', $to)
            ->groupBy('credited_invoice_id')
            ->select('credited_invoice_id')
            ->selectRaw('SUM(gross_amount) AS credited_amount');
        $openInvoices = DB::table('invoices')
            ->leftJoinSub($allocatedPayments, 'allocations', function ($join): void {
                $join->on('allocations.invoice_id', '=', 'invoices.id')
                    ->on('allocations.business_id', '=', 'invoices.business_id');
            })
            ->leftJoinSub($credits, 'credits', 'credits.credited_invoice_id', '=', 'invoices.id')
            ->where('invoices.document_type', 'invoice')
            ->where('invoices.business_id', $businessId)
            ->whereDate('invoices.issued_on', '<=', $to)
            ->where(fn ($query) => $query->whereIn('invoices.status', ['issued', 'partially_paid', 'paid'])
                ->orWhere(fn ($query) => $query->where('invoices.status', 'cancelled')->whereDate('invoices.cancelled_on', '>', $to)))
            ->get(['invoices.kind', 'invoices.gross_amount', 'invoices.due_on', 'allocations.paid_amount', 'credits.credited_amount']);

        $receivablesCents = 0;
        $overdueReceivablesCents = 0;
        $receivableInvoices = 0;
        $payablesCents = 0;
        $overduePayablesCents = 0;
        $payableInvoices = 0;

        foreach ($openInvoices as $invoice) {
            $outstandingCents = max(0, $this->toCents((string) $invoice->gross_amount) - $this->toCents((string) ($invoice->paid_amount ?? '0')) - $this->toCents((string) ($invoice->credited_amount ?? '0')));

            if ($outstandingCents === 0) {
                continue;
            }

            $isOverdue = $invoice->due_on !== null && $invoice->due_on < $to;

            if ($invoice->kind === 'sale') {
                $receivablesCents += $outstandingCents;
                $receivableInvoices++;

                if ($isOverdue) {
                    $overdueReceivablesCents += $outstandingCents;
                }
            } else {
                $payablesCents += $outstandingCents;
                $payableInvoices++;

                if ($isOverdue) {
                    $overduePayablesCents += $outstandingCents;
                }
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => $this->fromCents($revenueCents),
            'expenses' => $this->fromCents($expenseCents),
            'result' => $this->fromCents($revenueCents - $expenseCents),
            'sales_vat' => $this->fromCents($salesTaxCents),
            'purchase_vat' => $this->fromCents($purchaseTaxCents),
            'net_vat' => $this->fromCents($salesTaxCents - $purchaseTaxCents),
            'cash_balance' => $this->fromCents($this->postedBalanceCents($businessId, $cashAccountIds, $to)),
            'bank_balance' => $this->fromCents($this->postedBalanceCents($businessId, $bankAccountIds, $to)),
            'receivables' => $this->fromCents($receivablesCents),
            'overdue_receivables' => $this->fromCents($overdueReceivablesCents),
            'receivable_invoices' => $receivableInvoices,
            'payables' => $this->fromCents($payablesCents),
            'overdue_payables' => $this->fromCents($overduePayablesCents),
            'payable_invoices' => $payableInvoices,
            ...$this->payrollSummary($businessId, $from, $to),
            ...$this->stockSummary($businessId, $to),
        ];
    }

    /**
     * Payroll runs whose pay month falls in the period: gross total, employees paid, and whether all runs are approved.
     *
     * @return array{payroll_gross: string, payroll_employees: int, payroll_status: string|null}
     */
    private function payrollSummary(int $businessId, string $from, string $to): array
    {
        $fromKey = ((int) substr($from, 0, 4) * 100) + (int) substr($from, 5, 2);
        $toKey = ((int) substr($to, 0, 4) * 100) + (int) substr($to, 5, 2);
        $runs = DB::table('payroll_runs')
            ->where('business_id', $businessId)
            ->whereRaw('(period_year * 100 + period_month) BETWEEN ? AND ?', [$fromKey, $toKey])
            ->get(['id', 'status', 'gross_total']);

        if ($runs->isEmpty()) {
            return ['payroll_gross' => '0.00', 'payroll_employees' => 0, 'payroll_status' => null];
        }

        $employees = DB::table('payroll_lines')
            ->where('business_id', $businessId)
            ->whereIn('payroll_run_id', $runs->pluck('id')->all())
            ->distinct()
            ->count('employee_id');

        return [
            'payroll_gross' => $this->fromCents($runs->sum(fn (object $run): int => $this->toCents((string) $run->gross_total))),
            'payroll_employees' => $employees,
            'payroll_status' => $runs->contains(fn (object $run): bool => $run->status === 'draft') ? 'draft' : 'approved',
        ];
    }

    /**
     * Stock on hand at the end of the period, valued at each item's purchase price.
     *
     * @return array{stock_value: string, stock_items: int}
     */
    private function stockSummary(int $businessId, string $to): array
    {
        $quantities = DB::table('stock_movements as movements')
            ->join('inventory_items as items', function ($join): void {
                $join->on('items.id', '=', 'movements.inventory_item_id')
                    ->on('items.business_id', '=', 'movements.business_id');
            })
            ->where('movements.business_id', $businessId)
            ->whereDate('movements.occurred_on', '<=', $to)
            ->groupBy('items.id', 'items.purchase_price')
            ->havingRaw('SUM(movements.quantity_delta) > 0')
            ->selectRaw('items.purchase_price, SUM(movements.quantity_delta) AS on_hand')
            ->get();

        // A display figure, not a ledger amount: each item's quantity × price is rounded to cents, then summed.
        $valueCents = 0;
        foreach ($quantities as $row) {
            $valueCents += (int) round((float) $row->on_hand * (float) $row->purchase_price * 100);
        }

        return ['stock_value' => $this->fromCents($valueCents), 'stock_items' => $quantities->count()];
    }

    /** @param list<int> $accountIds */
    private function postedBalanceCents(int $businessId, array $accountIds, string $to): int
    {
        if ($accountIds === []) {
            return 0;
        }

        $balance = DB::table('journal_lines as lines')
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->on('entries.business_id', '=', 'lines.business_id');
            })
            ->where('lines.business_id', $businessId)
            ->where('entries.status', 'posted')
            ->whereDate('entries.entry_date', '<=', $to)
            ->whereIn('lines.account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(lines.debit - lines.credit), 0) AS balance')
            ->value('balance');

        return $this->toCents((string) ($balance ?? '0'));
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absoluteAmount = abs($amount);

        return $sign.intdiv($absoluteAmount, 100).'.'.str_pad((string) ($absoluteAmount % 100), 2, '0', STR_PAD_LEFT);
    }
}
