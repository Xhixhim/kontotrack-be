<?php

namespace App\Repositories\Eloquent;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\AccountingPeriodRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EloquentAccountingPeriodRepository implements AccountingPeriodRepository
{
    /** @return Collection<int, AccountingPeriod> */
    public function listForBusiness(Business $business): Collection
    {
        return $business->accountingPeriods()
            ->with(['reopenRequests' => fn ($query) => $query->where('status', 'pending')->with('requester:id,name')])
            ->orderBy('starts_on')
            ->get();
    }

    public function overlaps(Business $business, string $startsOn, string $endsOn): bool
    {
        return $business->accountingPeriods()
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): AccountingPeriod
    {
        return $business->accountingPeriods()->create($attributes);
    }

    public function findForUpdate(Business $business, int $periodId): AccountingPeriod
    {
        return $business->accountingPeriods()
            ->whereKey($periodId)
            ->lockForUpdate()
            ->with('journalEntries.lines')
            ->firstOrFail();
    }

    public function findForClosingReview(Business $business, int $periodId): AccountingPeriod
    {
        return $business->accountingPeriods()
            ->whereKey($periodId)
            ->with('journalEntries.lines')
            ->firstOrFail();
    }

    public function closingData(Business $business, AccountingPeriod $period): array
    {
        $businessId = (int) $business->getKey();
        $startsOn = $period->starts_on->toDateString();
        $endsOn = $period->ends_on->toDateString();

        $draftInvoiceCount = $business->invoices()
            ->whereBetween('issued_on', [$startsOn, $endsOn])
            ->where('status', 'draft')
            ->count();
        $purchaseInvoiceMissingDocumentCount = $business->invoices()
            ->where('kind', 'purchase')
            ->whereBetween('issued_on', [$startsOn, $endsOn])
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('document_id')
            ->count();
        $paymentMissingDocumentCount = $business->payments()
            ->whereBetween('paid_on', [$startsOn, $endsOn])
            ->whereNull('document_id')
            ->count();
        $bankTransactionMissingDocumentCount = DB::table('bank_transactions')
            ->where('business_id', $businessId)
            ->whereBetween('transaction_date', [$startsOn, $endsOn])
            ->whereNull('document_id')
            ->count();
        $missingJournalSourceCount = DB::table('journal_entries as entries')
            ->leftJoin('invoices', function ($join): void {
                $join->on('invoices.business_id', '=', 'entries.business_id')
                    ->where(fn ($query) => $query->whereColumn('invoices.journal_entry_id', 'entries.id')
                        ->orWhereColumn('invoices.cancellation_journal_entry_id', 'entries.id'));
            })
            ->where('entries.business_id', $businessId)
            ->where('entries.accounting_period_id', $period->getKey())
            ->where('entries.status', 'posted')
            ->whereNull('entries.document_id')
            ->whereNull('invoices.id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('payroll_runs')
                ->whereColumn('payroll_runs.journal_entry_id', 'entries.id')
                ->whereColumn('payroll_runs.business_id', 'entries.business_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('depreciation_runs')
                ->whereColumn('depreciation_runs.journal_entry_id', 'entries.id')
                ->whereColumn('depreciation_runs.business_id', 'entries.business_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('fixed_asset_events')
                ->whereColumn('fixed_asset_events.journal_entry_id', 'entries.id')
                ->whereColumn('fixed_asset_events.business_id', 'entries.business_id'))
            ->count();
        $unreconciledBankTransactionCount = DB::table('bank_transactions')
            ->where('business_id', $businessId)
            ->whereBetween('transaction_date', [$startsOn, $endsOn])
            ->where('is_reconciled', false)
            ->count();
        $negativeCashAccountCount = DB::table('cash_accounts as cash')
            ->join('journal_lines as lines', function ($join): void {
                $join->on('lines.account_id', '=', 'cash.account_id')
                    ->on('lines.business_id', '=', 'cash.business_id');
            })
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->on('entries.business_id', '=', 'lines.business_id');
            })
            ->where('cash.business_id', $businessId)
            ->where('cash.is_active', true)
            ->where('entries.status', 'posted')
            ->whereDate('entries.entry_date', '<=', $endsOn)
            ->groupBy('cash.id')
            ->havingRaw('SUM(lines.debit - lines.credit) < 0')
            ->get(['cash.id'])
            ->count();

        return [
            'draft_invoice_count' => $draftInvoiceCount,
            'purchase_invoice_missing_document_count' => $purchaseInvoiceMissingDocumentCount,
            'payment_missing_document_count' => $paymentMissingDocumentCount,
            'bank_transaction_missing_document_count' => $bankTransactionMissingDocumentCount,
            'missing_journal_source_count' => $missingJournalSourceCount,
            'unreconciled_bank_transaction_count' => $unreconciledBankTransactionCount,
            'negative_cash_account_count' => $negativeCashAccountCount,
        ];
    }

    public function markClosed(AccountingPeriod $period, User $user): AccountingPeriod
    {
        $period->forceFill([
            'status' => 'closed',
            'closed_by' => $user->getKey(),
            'closed_at' => now(),
        ])->save();

        return $period->refresh();
    }
}
