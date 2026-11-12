<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Repositories\Contracts\FinancialReportRepository;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentFinancialReportRepository implements FinancialReportRepository
{
    public function generate(Business $business, string $type, string $from, string $to): array
    {
        $businessId = (int) $business->getKey();
        $movementRange = $type === 'profit_loss' ? $from : null;
        $movements = $this->accountMovements($businessId, $movementRange, $to);
        $rows = [];
        $debitTotalCents = 0;
        $creditTotalCents = 0;
        $currentResultCents = 0;

        foreach ($movements as $account) {
            $debitCents = $this->toCents((string) $account->debit_total);
            $creditCents = $this->toCents((string) $account->credit_total);
            $netCents = $debitCents - $creditCents;

            if ($type === 'trial_balance') {
                [$rowDebitCents, $rowCreditCents] = $this->splitDebitCredit($netCents);
            } elseif ($type === 'profit_loss') {
                if (! in_array($account->account_type, ['revenue', 'expense'], true)) {
                    continue;
                }

                $naturalBalanceCents = $account->account_type === 'revenue' ? -$netCents : $netCents;
                [$rowDebitCents, $rowCreditCents] = $this->splitDebitCredit(
                    $account->account_type === 'revenue' ? -$naturalBalanceCents : $naturalBalanceCents,
                );
            } else {
                if (! in_array($account->account_type, ['asset', 'liability', 'equity'], true)) {
                    if ($account->account_type === 'revenue') {
                        $currentResultCents -= $netCents;
                    } elseif ($account->account_type === 'expense') {
                        $currentResultCents -= $netCents;
                    }

                    continue;
                }

                $naturalBalanceCents = in_array($account->account_type, ['asset'], true) ? $netCents : -$netCents;
                [$rowDebitCents, $rowCreditCents] = in_array($account->account_type, ['asset'], true)
                    ? $this->splitDebitCredit($naturalBalanceCents)
                    : $this->splitDebitCredit(-$naturalBalanceCents);
            }

            if ($rowDebitCents === 0 && $rowCreditCents === 0) {
                continue;
            }

            $rows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'account_type' => (string) $account->account_type,
                'debit' => $this->fromCents($rowDebitCents),
                'credit' => $this->fromCents($rowCreditCents),
            ];
            $debitTotalCents += $rowDebitCents;
            $creditTotalCents += $rowCreditCents;
        }

        if ($type === 'balance_sheet' && $currentResultCents !== 0) {
            [$resultDebitCents, $resultCreditCents] = $this->splitDebitCredit(-$currentResultCents);
            $rows[] = [
                'code' => '',
                'name' => 'Accumulated result from unclosed revenue and expenses',
                'account_type' => 'equity',
                'debit' => $this->fromCents($resultDebitCents),
                'credit' => $this->fromCents($resultCreditCents),
            ];
            $debitTotalCents += $resultDebitCents;
            $creditTotalCents += $resultCreditCents;
        }

        return [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'debit' => $this->fromCents($debitTotalCents),
                'credit' => $this->fromCents($creditTotalCents),
                'result' => $this->fromCents($creditTotalCents - $debitTotalCents),
            ],
        ];
    }

    public function generateInvoiceAging(Business $business, string $kind, string $asOf): array
    {
        $businessId = (int) $business->getKey();
        $allocations = DB::table('invoice_payment as allocations')
            ->join('payments', function ($join): void {
                $join->on('payments.id', '=', 'allocations.payment_id')
                    ->on('payments.business_id', '=', 'allocations.business_id');
            })
            ->where('payments.business_id', $businessId)
            ->whereDate('payments.paid_on', '<=', $asOf)
            ->groupBy('allocations.invoice_id', 'allocations.business_id')
            ->select(['allocations.invoice_id', 'allocations.business_id'])
            ->selectRaw('SUM(allocations.amount) AS allocated_amount');

        $credits = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('document_type', 'credit_note')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereDate('issued_on', '<=', $asOf)
            ->groupBy('credited_invoice_id')
            ->select('credited_invoice_id')
            ->selectRaw('SUM(gross_amount) AS credited_amount');
        $invoices = DB::table('invoices')
            ->join('counterparties', function ($join): void {
                $join->on('counterparties.id', '=', 'invoices.counterparty_id')
                    ->on('counterparties.business_id', '=', 'invoices.business_id');
            })
            ->leftJoinSub($allocations, 'allocations', function ($join): void {
                $join->on('allocations.invoice_id', '=', 'invoices.id')
                    ->on('allocations.business_id', '=', 'invoices.business_id');
            })
            ->leftJoinSub($credits, 'credits', 'credits.credited_invoice_id', '=', 'invoices.id')
            ->where('invoices.business_id', $businessId)
            ->where('invoices.document_type', 'invoice')
            ->where('invoices.kind', $kind)
            ->whereDate('invoices.issued_on', '<=', $asOf)
            ->where(fn ($query) => $query->whereIn('invoices.status', ['issued', 'partially_paid', 'paid'])
                ->orWhere(fn ($query) => $query->where('invoices.status', 'cancelled')->whereDate('invoices.cancelled_on', '>', $asOf)))
            ->orderBy('counterparties.name')
            ->orderBy('invoices.invoice_number')
            ->get([
                'invoices.id',
                'invoices.counterparty_id',
                'counterparties.name as counterparty_name',
                'invoices.invoice_number',
                'invoices.due_on',
                'invoices.gross_amount',
                'allocations.allocated_amount',
                'credits.credited_amount',
            ]);

        $bucketNames = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'];
        $rowsByCounterparty = [];
        $totalsCents = array_fill_keys($bucketNames, 0);
        $totalInvoiceCount = 0;

        foreach ($invoices as $invoice) {
            $outstandingCents = max(
                0,
                $this->toCents((string) $invoice->gross_amount) - $this->toCents((string) ($invoice->allocated_amount ?? '0.00')) - $this->toCents((string) ($invoice->credited_amount ?? '0.00')),
            );
            if ($outstandingCents === 0) {
                continue;
            }

            $bucket = $this->agingBucket($invoice->due_on, $asOf);
            $counterpartyId = (int) $invoice->counterparty_id;
            $rowsByCounterparty[$counterpartyId] ??= [
                'counterparty_id' => $counterpartyId,
                'counterparty_name' => (string) $invoice->counterparty_name,
                'invoice_count' => 0,
                'current' => 0,
                'days_1_30' => 0,
                'days_31_60' => 0,
                'days_61_90' => 0,
                'days_over_90' => 0,
                'total' => 0,
                'invoices' => [],
            ];
            $rowsByCounterparty[$counterpartyId]['invoice_count']++;
            $rowsByCounterparty[$counterpartyId][$bucket] += $outstandingCents;
            $rowsByCounterparty[$counterpartyId]['total'] += $outstandingCents;
            $rowsByCounterparty[$counterpartyId]['invoices'][] = [
                'id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'due_on' => $invoice->due_on,
                'outstanding_amount' => $this->fromCents($outstandingCents),
                'bucket' => $bucket,
            ];
            $totalsCents[$bucket] += $outstandingCents;
            $totalInvoiceCount++;
        }

        $rows = array_values($rowsByCounterparty);
        foreach ($rows as &$row) {
            foreach ([...$bucketNames, 'total'] as $amountKey) {
                $row[$amountKey] = $this->fromCents($row[$amountKey]);
            }
        }
        unset($row);

        $totals = ['invoice_count' => $totalInvoiceCount, 'total' => 0];
        foreach ($bucketNames as $bucketName) {
            $totals[$bucketName] = $this->fromCents($totalsCents[$bucketName]);
            $totals['total'] += $totalsCents[$bucketName];
        }
        $totals['total'] = $this->fromCents($totals['total']);

        return [
            'type' => $kind === 'sale' ? 'receivable_aging' : 'payable_aging',
            'as_of' => $asOf,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return list<array<string, mixed>>
     */
    public function generalLedger(Business $business, ?array $accountIds, string $from, string $to): array
    {
        $businessId = (int) $business->getKey();
        $postedLines = fn () => DB::table('journal_lines as lines')
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->on('entries.business_id', '=', 'lines.business_id');
            })
            ->where('lines.business_id', $businessId)
            ->where('entries.status', 'posted')
            ->when($accountIds !== null, fn ($query) => $query->whereIn('lines.account_id', $accountIds));
        $openings = $postedLines()
            ->whereDate('entries.entry_date', '<', $from)
            ->groupBy('lines.account_id')
            ->selectRaw('lines.account_id, SUM(lines.debit) AS debit_total, SUM(lines.credit) AS credit_total')
            ->get()
            ->keyBy('account_id');
        $lines = $postedLines()
            ->leftJoin('counterparties', function ($join): void {
                $join->on('counterparties.id', '=', 'lines.counterparty_id')
                    ->on('counterparties.business_id', '=', 'lines.business_id');
            })
            ->whereDate('entries.entry_date', '>=', $from)
            ->whereDate('entries.entry_date', '<=', $to)
            ->orderBy('entries.entry_date')
            ->orderBy('entries.id')
            ->orderBy('lines.id')
            ->get([
                'lines.account_id',
                'entries.id as journal_entry_id',
                'entries.entry_date',
                'entries.reference',
                'entries.description as entry_description',
                'lines.description as line_description',
                'counterparties.name as counterparty_name',
                'lines.debit',
                'lines.credit',
            ])
            ->groupBy('account_id');
        $accountIdsWithActivity = $openings->keys()->merge($lines->keys())->unique()->values();
        $accounts = $business->accounts()
            ->whereIn('id', $accountIds ?? $accountIdsWithActivity->all())
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'normal_balance']);
        $cards = [];

        foreach ($accounts as $account) {
            $opening = $openings->get($account->id);
            $openingCents = $opening === null ? 0 : $this->toCents((string) $opening->debit_total) - $this->toCents((string) $opening->credit_total);
            $runningCents = $openingCents;
            $debitTotalCents = 0;
            $creditTotalCents = 0;
            $cardLines = [];

            foreach ($lines->get($account->id, collect()) as $line) {
                $debitCents = $this->toCents((string) $line->debit);
                $creditCents = $this->toCents((string) $line->credit);
                $runningCents += $debitCents - $creditCents;
                $debitTotalCents += $debitCents;
                $creditTotalCents += $creditCents;
                $cardLines[] = [
                    'date' => substr((string) $line->entry_date, 0, 10),
                    'journal_entry_id' => (int) $line->journal_entry_id,
                    'reference' => $line->reference === null ? null : (string) $line->reference,
                    'description' => (string) ($line->line_description ?: $line->entry_description),
                    'counterparty_name' => $line->counterparty_name === null ? null : (string) $line->counterparty_name,
                    'debit' => $this->fromCents($debitCents),
                    'credit' => $this->fromCents($creditCents),
                    'balance' => $this->fromCents($runningCents),
                ];
            }

            $cards[] = [
                'account_id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'account_type' => (string) $account->account_type,
                'opening_balance' => $this->fromCents($openingCents),
                'debit_total' => $this->fromCents($debitTotalCents),
                'credit_total' => $this->fromCents($creditTotalCents),
                'closing_balance' => $this->fromCents($runningCents),
                'lines' => $cardLines,
            ];
        }

        return $cards;
    }

    /**
     * Issued, credited, and cancelled invoice lines that belong in the VAT records of a period. A cancellation is
     * reported on its cancellation date as a reversal of the original lines.
     *
     * @return Collection<int, object>
     */
    public function vatLines(Business $business, string $from, string $to): Collection
    {
        $businessId = (int) $business->getKey();
        $invoiceLineQuery = fn () => DB::table('invoices')
            ->join('counterparties', function ($join): void {
                $join->on('counterparties.id', '=', 'invoices.counterparty_id')
                    ->on('counterparties.business_id', '=', 'invoices.business_id');
            })
            ->join('invoice_lines', function ($join): void {
                $join->on('invoice_lines.invoice_id', '=', 'invoices.id')
                    ->on('invoice_lines.business_id', '=', 'invoices.business_id');
            })
            ->where('invoices.business_id', $businessId)
            ->whereIn('invoices.kind', ['sale', 'purchase'])
            ->orderBy('invoices.invoice_number')
            ->orderBy('invoice_lines.position')
            ->select([
                'invoices.id as invoice_id',
                'invoices.counterparty_id',
                'invoices.kind',
                'invoices.invoice_number',
                'invoices.issued_on',
                'invoices.cancelled_on',
                'invoices.document_type',
                'counterparties.name as counterparty_name',
                'counterparties.nui as counterparty_nui',
                'counterparties.country_code as counterparty_country',
                'invoice_lines.vat_code',
                'invoice_lines.net_amount',
                'invoice_lines.tax_amount',
                'invoice_lines.gross_amount',
                'invoice_lines.tax_snapshot',
            ]);
        $issuedLines = $invoiceLineQuery()
            ->whereIn('invoices.status', ['issued', 'partially_paid', 'paid', 'cancelled'])
            ->whereDate('invoices.issued_on', '>=', $from)
            ->whereDate('invoices.issued_on', '<=', $to)
            ->get()
            ->map(function (object $line): object {
                $isCreditNote = $line->document_type === 'credit_note';
                $line->entry_type = $isCreditNote ? 'credit_note' : 'invoice';
                $line->reported_on = substr((string) $line->issued_on, 0, 10);
                $line->sign = $isCreditNote ? -1 : 1;

                return $line;
            });
        $cancellationLines = $invoiceLineQuery()
            ->where('invoices.status', 'cancelled')
            ->whereDate('invoices.cancelled_on', '>=', $from)
            ->whereDate('invoices.cancelled_on', '<=', $to)
            ->get()
            ->map(function (object $line): object {
                $line->entry_type = 'cancellation';
                $line->reported_on = substr((string) $line->cancelled_on, 0, 10);
                $line->sign = -1;

                return $line;
            });

        return $issuedLines->concat($cancellationLines)
            ->sortBy(fn (object $line): string => $line->reported_on.'|'.($line->entry_type === 'invoice' ? '0' : '1').'|'.$line->invoice_number)
            ->values();
    }

    public function generateVatSummary(Business $business, string $from, string $to): array
    {
        $invoiceLines = $this->vatLines($business, $from, $to);

        $rowsByInvoiceAndVatCode = [];
        $summaryByKindAndVatCode = [];
        $totals = [
            'sales' => ['net_cents' => 0, 'tax_cents' => 0, 'gross_cents' => 0],
            'purchases' => ['net_cents' => 0, 'tax_cents' => 0, 'gross_cents' => 0],
        ];

        foreach ($invoiceLines as $invoiceLine) {
            $taxSnapshot = json_decode((string) $invoiceLine->tax_snapshot, true) ?: [];
            $kind = (string) $invoiceLine->kind;
            $section = $kind === 'sale' ? 'sales' : 'purchases';
            $vatCode = (string) $invoiceLine->vat_code;
            $ratePercent = (string) ($taxSnapshot['rate_percent'] ?? '0');
            $treatment = (string) ($taxSnapshot['treatment'] ?? 'unknown');
            $vatLabel = (string) ($taxSnapshot['label'] ?? $vatCode);
            $netCents = $invoiceLine->sign * $this->toCents((string) $invoiceLine->net_amount);
            $taxCents = $invoiceLine->sign * $this->toCents((string) $invoiceLine->tax_amount);
            $grossCents = $invoiceLine->sign * $this->toCents((string) $invoiceLine->gross_amount);
            $rowKey = implode('|', [
                $invoiceLine->entry_type,
                (string) $invoiceLine->invoice_id,
                $vatCode,
                $ratePercent,
                $treatment,
            ]);

            $rowsByInvoiceAndVatCode[$rowKey] ??= [
                'invoice_id' => (int) $invoiceLine->invoice_id,
                'kind' => $kind,
                'invoice_number' => (string) $invoiceLine->invoice_number,
                'issued_on' => (string) $invoiceLine->issued_on,
                'entry_type' => $invoiceLine->entry_type,
                'reported_on' => $invoiceLine->reported_on,
                'counterparty_name' => (string) $invoiceLine->counterparty_name,
                'counterparty_nui' => $invoiceLine->counterparty_nui === null ? null : (string) $invoiceLine->counterparty_nui,
                'vat_code' => $vatCode,
                'vat_label' => $vatLabel,
                'rate_percent' => $ratePercent,
                'treatment' => $treatment,
                'net_cents' => 0,
                'tax_cents' => 0,
                'gross_cents' => 0,
            ];
            $rowsByInvoiceAndVatCode[$rowKey]['net_cents'] += $netCents;
            $rowsByInvoiceAndVatCode[$rowKey]['tax_cents'] += $taxCents;
            $rowsByInvoiceAndVatCode[$rowKey]['gross_cents'] += $grossCents;

            $summaryKey = implode('|', [$kind, $vatCode, $ratePercent, $treatment]);
            $summaryByKindAndVatCode[$summaryKey] ??= [
                'kind' => $kind,
                'vat_code' => $vatCode,
                'vat_label' => $vatLabel,
                'rate_percent' => $ratePercent,
                'treatment' => $treatment,
                'net_cents' => 0,
                'tax_cents' => 0,
                'gross_cents' => 0,
            ];
            $summaryByKindAndVatCode[$summaryKey]['net_cents'] += $netCents;
            $summaryByKindAndVatCode[$summaryKey]['tax_cents'] += $taxCents;
            $summaryByKindAndVatCode[$summaryKey]['gross_cents'] += $grossCents;
            $totals[$section]['net_cents'] += $netCents;
            $totals[$section]['tax_cents'] += $taxCents;
            $totals[$section]['gross_cents'] += $grossCents;
        }

        $toMoneyRows = fn (array $rows): array => array_map(function (array $row): array {
            $row['net_amount'] = $this->fromCents($row['net_cents']);
            $row['tax_amount'] = $this->fromCents($row['tax_cents']);
            $row['gross_amount'] = $this->fromCents($row['gross_cents']);
            unset($row['net_cents'], $row['tax_cents'], $row['gross_cents']);

            return $row;
        }, array_values($rows));

        $summary = $toMoneyRows($summaryByKindAndVatCode);
        foreach (['sales', 'purchases'] as $section) {
            foreach (['net', 'tax', 'gross'] as $amount) {
                $totals[$section][$amount.'_amount'] = $this->fromCents($totals[$section][$amount.'_cents']);
                unset($totals[$section][$amount.'_cents']);
            }
        }

        return [
            'type' => 'vat_summary',
            'from' => $from,
            'to' => $to,
            'currency' => $business->currency,
            'rows' => $toMoneyRows($rowsByInvoiceAndVatCode),
            'summary' => $summary,
            'totals' => [
                ...$totals,
                'invoice_vat_difference' => $this->fromCents(
                    $this->toCents($totals['sales']['tax_amount']) - $this->toCents($totals['purchases']['tax_amount']),
                ),
                'invoice_count' => $invoiceLines->pluck('invoice_id')->unique()->count(),
                'missing_counterparty_nui_count' => $invoiceLines
                    ->filter(fn (object $invoiceLine): bool => $invoiceLine->counterparty_nui === null)
                    ->pluck('invoice_id')
                    ->unique()
                    ->count(),
            ],
        ];
    }

    private function agingBucket(?string $dueOn, string $asOf): string
    {
        if ($dueOn === null || $dueOn >= $asOf) {
            return 'current';
        }

        $daysOverdue = (int) (new DateTimeImmutable($dueOn))->diff(new DateTimeImmutable($asOf))->format('%a');

        return match (true) {
            $daysOverdue <= 30 => 'days_1_30',
            $daysOverdue <= 60 => 'days_31_60',
            $daysOverdue <= 90 => 'days_61_90',
            default => 'days_over_90',
        };
    }

    /** @return Collection<int, object{code: string, name: string, account_type: string, debit_total: string, credit_total: string}> */
    private function accountMovements(int $businessId, ?string $from, string $to): Collection
    {
        $postedMovements = DB::table('journal_lines as lines')
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->on('entries.business_id', '=', 'lines.business_id');
            })
            ->where('entries.business_id', $businessId)
            ->where('entries.status', 'posted')
            ->whereDate('entries.entry_date', '<=', $to)
            ->when($from !== null, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
            ->select(['lines.account_id', 'lines.business_id'])
            ->selectRaw('SUM(lines.debit) AS debit_total, SUM(lines.credit) AS credit_total')
            ->groupBy('lines.account_id', 'lines.business_id');

        return DB::table('accounts')
            ->leftJoinSub($postedMovements, 'movements', function ($join): void {
                $join->on('movements.account_id', '=', 'accounts.id')
                    ->on('movements.business_id', '=', 'accounts.business_id');
            })
            ->where('accounts.business_id', $businessId)
            ->orderBy('accounts.code')
            ->get([
                'accounts.code',
                'accounts.name',
                'accounts.account_type',
                DB::raw('COALESCE(movements.debit_total, 0) AS debit_total'),
                DB::raw('COALESCE(movements.credit_total, 0) AS credit_total'),
            ]);
    }

    /** @return array{int, int} */
    private function splitDebitCredit(int $netDebitCents): array
    {
        return [max($netDebitCents, 0), max(-$netDebitCents, 0)];
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
