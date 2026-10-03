<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Repositories\Contracts\BusinessControlRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EloquentBusinessControlRepository implements BusinessControlRepository
{
    private const OPEN_INVOICE_STATUSES = ['issued', 'partially_paid'];

    private const EXAMPLE_LIMIT = 10;

    /** @return array<string, mixed> */
    public function controlData(Business $business, string $today): array
    {
        $businessId = (int) $business->getKey();
        $date = Carbon::parse($today);

        return [
            'negative_cash_accounts' => $this->negativeCashAccounts($businessId),
            'mapped_posting_purposes' => $business->postingAccounts()
                ->whereHas('account', fn ($query) => $query->where('is_active', true))
                ->pluck('purpose')
                ->all(),
            'counterparties_missing_nui' => $business->counterparties()
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('nui')->orWhere('nui', ''))
                ->orderBy('name')
                ->get(['id', 'name']),
            'duplicate_counterparty_nuis' => $business->counterparties()
                ->whereNotNull('nui')
                ->where('nui', '!=', '')
                ->groupBy('nui')
                ->havingRaw('COUNT(*) > 1')
                ->selectRaw('nui, COUNT(*) AS contact_count')
                ->get(),
            'possible_duplicate_purchases' => $this->possibleDuplicatePurchases($businessId),
            'overdue_receivables' => $this->overdueInvoices($businessId, 'sale', $today),
            'overdue_payables' => $this->overdueInvoices($businessId, 'purchase', $today),
            'stale_drafts' => $business->invoices()
                ->where('status', 'draft')
                ->whereDate('issued_on', '<', $date->copy()->subDays(14)->toDateString())
                ->orderBy('issued_on')
                ->limit(self::EXAMPLE_LIMIT)
                ->get(['id', 'kind', 'invoice_number', 'issued_on']),
            'stale_draft_count' => $business->invoices()
                ->where('status', 'draft')
                ->whereDate('issued_on', '<', $date->copy()->subDays(14)->toDateString())
                ->count(),
            'old_unreconciled_bank_transactions' => DB::table('bank_transactions')
                ->where('business_id', $businessId)
                ->where('is_reconciled', false)
                ->whereDate('transaction_date', '<', $date->copy()->subDays(30)->toDateString())
                ->count(),
            'low_stock_items' => $this->lowStockItems($businessId),
            'journal_entries_without_source' => $this->journalEntriesWithoutSource($businessId),
            'sales_net_this_year' => $this->salesNetForYear($businessId, $date->year, $today),
            'overdue_tasks' => $business->tasks()->where('status', 'open')->whereDate('due_on', '<', $today)->orderBy('due_on')->get(['id', 'kind', 'title', 'due_on']),
            'tasks_due_soon' => $business->tasks()->where('status', 'open')->whereBetween('due_on', [$today, $date->copy()->addDays(7)->toDateString()])->count(),
            'open_document_requests' => $business->documentRequests()->where('status', 'open')->count(),
            'overdue_document_requests' => $business->documentRequests()->where('status', 'open')->whereNotNull('due_on')->whereDate('due_on', '<', $today)->get(['id', 'title', 'due_on']),
            'unusual_transactions' => $this->unusualTransactions($businessId, $date),
            'has_active_employees' => $business->employees()->where('status', 'active')->exists(),
            'previous_month' => $date->copy()->startOfMonth()->subMonth()->format('Y-m'),
            'previous_month_employee_count' => $business->employees()
                ->where('status', 'active')
                ->whereDate('employment_start', '<=', $date->copy()->startOfMonth()->subDay()->toDateString())
                ->where(fn ($query) => $query->whereNull('employment_end')->orWhereDate('employment_end', '>=', $date->copy()->startOfMonth()->subMonth()->toDateString()))
                ->count(),
            'previous_month_payroll_status' => $business->payrollRuns()
                ->where('period_year', $date->copy()->startOfMonth()->subMonth()->year)
                ->where('period_month', $date->copy()->startOfMonth()->subMonth()->month)
                ->value('status'),
        ];
    }

    /** @return list<array{id: int, name: string, balance: string}> */
    private function negativeCashAccounts(int $businessId): array
    {
        return DB::table('cash_accounts as cash')
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
            ->groupBy('cash.id', 'cash.name')
            ->havingRaw('SUM(lines.debit - lines.credit) < 0')
            ->selectRaw('cash.id, cash.name, SUM(lines.debit - lines.credit) AS balance')
            ->get()
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'balance' => $this->fromCents($this->toCents((string) $row->balance))])
            ->all();
    }

    /** @return list<array{invoice_ids: list<int>, invoice_numbers: list<string>, counterparty_name: string, issued_on: string, gross_amount: string}> */
    private function possibleDuplicatePurchases(int $businessId): array
    {
        $groups = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('kind', 'purchase')
            ->where('document_type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->groupBy('counterparty_id', 'issued_on', 'gross_amount')
            ->havingRaw('COUNT(*) > 1')
            ->select(['counterparty_id', 'issued_on', 'gross_amount'])
            ->limit(self::EXAMPLE_LIMIT)
            ->get();

        return $groups->map(function (object $group) use ($businessId): array {
            $invoices = DB::table('invoices')
                ->join('counterparties', function ($join): void {
                    $join->on('counterparties.id', '=', 'invoices.counterparty_id')
                        ->on('counterparties.business_id', '=', 'invoices.business_id');
                })
                ->where('invoices.business_id', $businessId)
                ->where('invoices.kind', 'purchase')
                ->where('invoices.document_type', 'invoice')
                ->whereIn('invoices.status', ['issued', 'partially_paid', 'paid'])
                ->where('invoices.counterparty_id', $group->counterparty_id)
                ->where('invoices.issued_on', $group->issued_on)
                ->where('invoices.gross_amount', $group->gross_amount)
                ->orderBy('invoices.id')
                ->get(['invoices.id', 'invoices.invoice_number', 'counterparties.name']);

            return [
                'invoice_ids' => $invoices->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'invoice_numbers' => $invoices->pluck('invoice_number')->map(fn ($number): string => (string) $number)->all(),
                'counterparty_name' => (string) $invoices->first()?->name,
                'issued_on' => substr((string) $group->issued_on, 0, 10),
                'gross_amount' => (string) $group->gross_amount,
            ];
        })->all();
    }

    /** @return array{count: int, outstanding_cents: int, examples: list<array{id: int, invoice_number: string, counterparty_name: string, due_on: string}>} */
    private function overdueInvoices(int $businessId, string $kind, string $today): array
    {
        $allocations = DB::table('invoice_payment')
            ->where('business_id', $businessId)
            ->groupBy('invoice_id')
            ->select('invoice_id')
            ->selectRaw('SUM(amount) AS paid_amount');
        $invoices = DB::table('invoices')
            ->join('counterparties', function ($join): void {
                $join->on('counterparties.id', '=', 'invoices.counterparty_id')
                    ->on('counterparties.business_id', '=', 'invoices.business_id');
            })
            ->leftJoinSub($allocations, 'allocations', 'allocations.invoice_id', '=', 'invoices.id')
            ->where('invoices.business_id', $businessId)
            ->where('invoices.kind', $kind)
            ->where('invoices.document_type', 'invoice')
            ->whereIn('invoices.status', self::OPEN_INVOICE_STATUSES)
            ->whereNotNull('invoices.due_on')
            ->whereDate('invoices.due_on', '<', $today)
            ->orderBy('invoices.due_on')
            ->get(['invoices.id', 'invoices.invoice_number', 'invoices.due_on', 'invoices.gross_amount', 'invoices.credited_total', 'allocations.paid_amount', 'counterparties.name']);

        $outstandingCents = $invoices->sum(fn (object $invoice): int => $this->toCents((string) $invoice->gross_amount) - $this->toCents((string) ($invoice->paid_amount ?? '0')) - $this->toCents((string) $invoice->credited_total));

        return [
            'count' => $invoices->count(),
            'outstanding_cents' => $outstandingCents,
            'examples' => $invoices->take(self::EXAMPLE_LIMIT)->map(fn (object $invoice): array => [
                'id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'counterparty_name' => (string) $invoice->name,
                'due_on' => substr((string) $invoice->due_on, 0, 10),
            ])->values()->all(),
        ];
    }

    /** @return list<array{id: int, sku: string, name: string, on_hand: string, minimum_quantity: string}> */
    private function lowStockItems(int $businessId): array
    {
        $balances = DB::table('stock_movements')
            ->where('business_id', $businessId)
            ->groupBy('inventory_item_id')
            ->select('inventory_item_id')
            ->selectRaw('SUM(quantity_delta) AS on_hand');

        return DB::table('inventory_items as items')
            ->leftJoinSub($balances, 'balances', 'balances.inventory_item_id', '=', 'items.id')
            ->where('items.business_id', $businessId)
            ->where('items.is_active', true)
            ->where('items.minimum_quantity', '>', 0)
            ->whereRaw('COALESCE(balances.on_hand, 0) < items.minimum_quantity')
            ->orderBy('items.name')
            ->get(['items.id', 'items.sku', 'items.name', 'items.minimum_quantity', 'balances.on_hand'])
            ->map(fn (object $item): array => [
                'id' => (int) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'on_hand' => (string) ($item->on_hand ?? '0'),
                'minimum_quantity' => (string) $item->minimum_quantity,
            ])
            ->all();
    }

    private function journalEntriesWithoutSource(int $businessId): int
    {
        return DB::table('journal_entries as entries')
            ->leftJoin('invoices', function ($join): void {
                $join->on('invoices.business_id', '=', 'entries.business_id')
                    ->where(fn ($query) => $query->whereColumn('invoices.journal_entry_id', 'entries.id')
                        ->orWhereColumn('invoices.cancellation_journal_entry_id', 'entries.id'));
            })
            ->where('entries.business_id', $businessId)
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
    }

    private function salesNetForYear(int $businessId, int $year, string $today): int
    {
        $from = "{$year}-01-01";
        $credited = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('kind', 'sale')
            ->where('document_type', 'credit_note')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereBetween('issued_on', [$from, $today])
            ->sum('net_amount');
        $issued = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('kind', 'sale')
            ->where('document_type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'cancelled'])
            ->whereBetween('issued_on', [$from, $today])
            ->sum('net_amount');
        $cancelled = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('kind', 'sale')
            ->where('status', 'cancelled')
            ->whereBetween('cancelled_on', [$from, $today])
            ->sum('net_amount');

        return $this->toCents((string) $issued) - $this->toCents((string) $cancelled) - $this->toCents((string) $credited);
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absoluteAmount = abs($amount);

        return $sign.intdiv($absoluteAmount, 100).'.'.str_pad((string) ($absoluteAmount % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Recent invoices and payments more than five times the business's usual amount, judged against at least ten earlier ones from the past year.
     *
     * @return list<array{type: string, id: int, reference: string, date: string, amount: string, typical_amount: string}>
     */
    private function unusualTransactions(int $businessId, Carbon $date): array
    {
        $recentFrom = $date->copy()->subDays(30)->toDateString();
        $baselineFrom = $date->copy()->subYear()->toDateString();
        $sources = [
            'invoice' => DB::table('invoices')->where('business_id', $businessId)->where('document_type', 'invoice')->whereIn('status', ['issued', 'partially_paid', 'paid'])->select(['id', 'invoice_number as reference', 'issued_on as occurred_on', 'gross_amount as amount', 'kind as group_key']),
            'payment' => DB::table('payments')->where('business_id', $businessId)->select(['id', 'reference', 'paid_on as occurred_on', 'amount', 'direction as group_key']),
        ];
        $flagged = [];

        foreach ($sources as $type => $query) {
            $rows = $query->whereDate($type === 'invoice' ? 'issued_on' : 'paid_on', '>=', $baselineFrom)->get();

            foreach ($rows->groupBy('group_key') as $group) {
                $baseline = $group->filter(fn (object $row): bool => substr((string) $row->occurred_on, 0, 10) < $recentFrom);

                if ($baseline->count() < 10) {
                    continue;
                }

                $averageCents = intdiv($baseline->sum(fn (object $row): int => $this->toCents((string) $row->amount)), $baseline->count());

                foreach ($group as $row) {
                    $amountCents = $this->toCents((string) $row->amount);

                    if (substr((string) $row->occurred_on, 0, 10) >= $recentFrom && $averageCents > 0 && $amountCents > $averageCents * 5) {
                        $flagged[] = [
                            'type' => $type,
                            'id' => (int) $row->id,
                            'reference' => (string) ($row->reference ?? ''),
                            'date' => substr((string) $row->occurred_on, 0, 10),
                            'amount' => $this->fromCents($amountCents),
                            'typical_amount' => $this->fromCents($averageCents),
                        ];
                    }
                }
            }
        }

        return array_slice($flagged, 0, self::EXAMPLE_LIMIT);
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }
}
