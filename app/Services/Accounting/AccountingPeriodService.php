<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\AccountingPeriodRepository;
use App\Services\Dashboard\BusinessDashboardService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    public function __construct(
        private AccountingPeriodRepository $periodRepository,
        private BusinessDashboardService $dashboardService,
    ) {}

    /** @return Collection<int, AccountingPeriod> */
    public function listForBusiness(Business $business): Collection
    {
        return $this->periodRepository->listForBusiness($business);
    }

    /** @param array{name: string, starts_on: string, ends_on: string} $attributes */
    public function createForBusiness(Business $business, int $userId, array $attributes): AccountingPeriod
    {
        return DB::transaction(function () use ($business, $userId, $attributes): AccountingPeriod {
            Business::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();

            if ($this->periodRepository->overlaps($business, $attributes['starts_on'], $attributes['ends_on'])) {
                throw ValidationException::withMessages(['starts_on' => ['This period overlaps an existing accounting period.']]);
            }

            $period = $this->periodRepository->createForBusiness($business, $attributes);
            $business->auditLogs()->create([
                'user_id' => $userId,
                'action' => 'accounting_period.created',
                'subject_type' => AccountingPeriod::class,
                'subject_id' => $period->getKey(),
                'new_values' => $period->only(['name', 'starts_on', 'ends_on']),
            ]);

            return $period;
        });
    }

    public function close(Business $business, User $user, int $periodId): AccountingPeriod
    {
        return DB::transaction(function () use ($business, $user, $periodId): AccountingPeriod {
            $period = $this->periodRepository->findForUpdate($business, $periodId);

            $closingReview = $this->buildClosingReview($business, $period);
            $blockingCheck = collect($closingReview['checks'])->firstWhere('status', 'blocked');

            if ($blockingCheck !== null) {
                throw ValidationException::withMessages(['accounting_period' => [$blockingCheck['detail']]]);
            }

            $closedPeriod = $this->periodRepository->markClosed($period, $user);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'accounting_period.closed',
                'subject_type' => AccountingPeriod::class,
                'subject_id' => $period->getKey(),
                'old_values' => ['status' => 'open'],
                'new_values' => [
                    'status' => 'closed',
                    'closed_at' => $closedPeriod->closed_at?->toISOString(),
                    'closing_checks' => $closingReview['checks'],
                ],
            ]);

            return $closedPeriod;
        });
    }

    /** @return array{period_id: int, name: string, starts_on: string, ends_on: string, can_close: bool, checks: list<array{key: string, label: string, status: string, detail: string}>} */
    public function closingChecks(Business $business, int $periodId): array
    {
        $period = $this->periodRepository->findForClosingReview($business, $periodId);

        return $this->buildClosingReview($business, $period);
    }

    /** @return array{period_id: int, name: string, starts_on: string, ends_on: string, can_close: bool, checks: list<array{key: string, label: string, status: string, detail: string}>} */
    private function buildClosingReview(Business $business, AccountingPeriod $period): array
    {
        $draftJournalEntries = $period->journalEntries->where('status', 'draft')->count();
        $invalidPostedJournalEntries = 0;

        foreach ($period->journalEntries->where('status', 'posted') as $entry) {
            $isOutsidePeriod = $entry->entry_date->toDateString() < $period->starts_on->toDateString()
                || $entry->entry_date->toDateString() > $period->ends_on->toDateString();
            $debitCents = 0;
            $creditCents = 0;
            $invalidLine = false;

            foreach ($entry->lines as $line) {
                $debit = $this->toCents($line->debit);
                $credit = $this->toCents($line->credit);

                if (($debit > 0) === ($credit > 0)) {
                    $invalidLine = true;
                }

                $debitCents += $debit;
                $creditCents += $credit;
            }

            if ($isOutsidePeriod || $invalidLine || $entry->lines->count() < 2 || $debitCents !== $creditCents) {
                $invalidPostedJournalEntries++;
            }
        }

        $details = $this->periodRepository->closingData($business, $period);
        $financialSummary = $this->dashboardService->summaryForBusiness(
            $business,
            $period->starts_on->toDateString(),
            $period->ends_on->toDateString(),
        );
        $journalStatus = $draftJournalEntries > 0 || $invalidPostedJournalEntries > 0 ? 'blocked' : 'passed';
        $journalDetail = $journalStatus === 'passed'
            ? 'All journal entries in this period are posted, balanced, and dated inside the period.'
            : "Resolve {$draftJournalEntries} draft and {$invalidPostedJournalEntries} invalid posted journal entries before closing.";
        $purchaseDocumentStatus = $details['purchase_invoice_missing_document_count'] > 0 ? 'blocked' : 'passed';
        $purchaseDocumentDetail = $purchaseDocumentStatus === 'passed'
            ? 'Every issued purchase invoice in this period has a source bill attached.'
            : "Attach source bills to {$details['purchase_invoice_missing_document_count']} issued purchase invoice(s).";
        $paymentDocumentStatus = $details['payment_missing_document_count'] > 0 ? 'warning' : 'passed';
        $paymentDocumentDetail = $paymentDocumentStatus === 'passed'
            ? 'Every recorded cash or bank payment in this period has a source document attached.'
            : "Attach source documents to {$details['payment_missing_document_count']} recorded payment(s).";
        $bankDocumentStatus = $details['bank_transaction_missing_document_count'] > 0 ? 'warning' : 'passed';
        $bankDocumentDetail = $bankDocumentStatus === 'passed'
            ? 'Every bank transaction in this period links to its imported source statement or payment document.'
            : "Attach a source statement or payment document to {$details['bank_transaction_missing_document_count']} bank transaction(s).";
        $checks = [
            [
                'key' => 'journal_entries',
                'label' => 'Journal entries',
                'status' => $period->status === 'open' ? $journalStatus : 'blocked',
                'detail' => $period->status === 'open' ? $journalDetail : 'This accounting period is already closed.',
            ],
            [
                'key' => 'purchase_documents',
                'label' => 'Purchase source documents',
                'status' => $purchaseDocumentStatus,
                'detail' => $purchaseDocumentDetail,
            ],
            [
                'key' => 'payment_documents',
                'label' => 'Payment source documents',
                'status' => $paymentDocumentStatus,
                'detail' => $paymentDocumentDetail,
            ],
            [
                'key' => 'bank_transaction_documents',
                'label' => 'Bank transaction source documents',
                'status' => $bankDocumentStatus,
                'detail' => $bankDocumentDetail,
            ],
            [
                'key' => 'draft_invoices',
                'label' => 'Draft invoices',
                'status' => $details['draft_invoice_count'] > 0 ? 'warning' : 'passed',
                'detail' => $details['draft_invoice_count'] > 0
                    ? "There are {$details['draft_invoice_count']} draft invoice(s) dated in this period. Review whether they should be issued or removed."
                    : 'There are no draft invoices dated in this period.',
            ],
            [
                'key' => 'source_documents',
                'label' => 'Journal source documents',
                'status' => $details['missing_journal_source_count'] > 0 ? 'warning' : 'passed',
                'detail' => $details['missing_journal_source_count'] > 0
                    ? "{$details['missing_journal_source_count']} posted journal entry(ies) have no linked source document."
                    : 'Every posted journal entry has a linked source document.',
            ],
            [
                'key' => 'bank_reconciliation',
                'label' => 'Bank reconciliation',
                'status' => $details['unreconciled_bank_transaction_count'] > 0 ? 'warning' : 'passed',
                'detail' => $details['unreconciled_bank_transaction_count'] > 0
                    ? "{$details['unreconciled_bank_transaction_count']} bank transaction(s) remain unreconciled."
                    : 'All recorded bank transactions in this period are reconciled.',
            ],
            [
                'key' => 'cash_balance',
                'label' => 'Cash balance',
                'status' => $details['negative_cash_account_count'] > 0 ? 'warning' : 'passed',
                'detail' => $details['negative_cash_account_count'] > 0
                    ? "{$details['negative_cash_account_count']} cash account(s) have a negative posted balance at period end."
                    : 'No cash account has a negative posted balance at period end.',
            ],
            [
                'key' => 'customer_supplier_balances',
                'label' => 'Customer and supplier balances',
                'status' => $this->toCents($financialSummary['receivables']) > 0 || $this->toCents($financialSummary['payables']) > 0 ? 'warning' : 'passed',
                'detail' => 'Customer balances: '.$financialSummary['receivables'].'; supplier balances: '.$financialSummary['payables'].'.',
            ],
            [
                'key' => 'vat_summary',
                'label' => 'Invoice VAT summary',
                'status' => 'passed',
                'detail' => 'Posted invoice VAT for the period: sales '.$financialSummary['sales_vat'].', purchases '.$financialSummary['purchase_vat'].', net '.$financialSummary['net_vat'].'. This is not a tax declaration.',
            ],
            [
                'key' => 'payroll',
                'label' => 'Payroll review',
                'status' => 'not_implemented',
                'detail' => 'Payroll is not available in this version and was not included in the close review.',
            ],
            [
                'key' => 'inventory',
                'label' => 'Inventory review',
                'status' => 'not_implemented',
                'detail' => 'Inventory is not available in this version and was not included in the close review.',
            ],
        ];

        return [
            'period_id' => (int) $period->getKey(),
            'name' => $period->name,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'can_close' => ! collect($checks)->contains(fn (array $check): bool => $check['status'] === 'blocked'),
            'checks' => $checks,
        ];
    }

    private function toCents(string|int|float $amount): int
    {
        $value = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }
}
