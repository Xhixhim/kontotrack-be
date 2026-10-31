<?php

namespace App\Services\Controls;

use App\Models\Business;
use App\Repositories\Contracts\BusinessControlRepository;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use App\Services\Reports\BankReconciliationReportService;

class BusinessControlService
{
    public function __construct(
        private BusinessControlRepository $controlRepository,
        private ComplianceRuleSetRepository $complianceRuleSetRepository,
        private BankReconciliationReportService $bankReconciliationReport,
    ) {}

    /**
     * Run the automatic controls and return each result as critical, warning, ok, or info.
     *
     * @return array{as_of: string, summary: array<string, int>, checks: list<array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}>}
     */
    public function run(Business $business, string $today): array
    {
        $data = $this->controlRepository->controlData($business, $today);
        $checks = [
            $this->vatStatusCheck($business),
            $this->postingAccountsCheck($business, $data['mapped_posting_purposes'], $data['has_active_employees']),
            $this->negativeCashCheck($data['negative_cash_accounts']),
            $this->duplicatePurchasesCheck($data['possible_duplicate_purchases']),
            $this->missingNuiCheck($data['counterparties_missing_nui']),
            $this->duplicateNuiCheck($data['duplicate_counterparty_nuis']),
            $this->overdueCheck('overdue_receivables', 'Overdue customer invoices', $data['overdue_receivables'], 'customer invoice(s) are past due'),
            $this->overdueCheck('overdue_payables', 'Overdue supplier bills', $data['overdue_payables'], 'supplier bill(s) are past due'),
            $this->staleDraftsCheck($data['stale_draft_count'], $data['stale_drafts']),
            $this->bankReconciliationCheck($data['old_unreconciled_bank_transactions']),
            $this->bankDifferencesCheck($business, $today),
            $this->lowStockCheck($data['low_stock_items']),
            $this->journalSourceCheck($data['journal_entries_without_source']),
            $this->vatThresholdCheck($business, $today, $data['sales_net_this_year']),
            $this->payrollCheck($data['previous_month'], $data['previous_month_employee_count'], $data['previous_month_payroll_status']),
            $this->deadlinesCheck($data['overdue_tasks'], $data['tasks_due_soon']),
            $this->documentRequestsCheck($data['open_document_requests'], $data['overdue_document_requests']),
            $this->unusualTransactionsCheck($data['unusual_transactions']),
        ];
        $summary = ['critical' => 0, 'warning' => 0, 'ok' => 0, 'info' => 0];

        foreach ($checks as $check) {
            $summary[$check['severity']]++;
        }

        return ['as_of' => $today, 'summary' => $summary, 'checks' => $checks];
    }

    /**
     * Compares each bank account's books with its latest imported statement balance.
     *
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function bankDifferencesCheck(Business $business, string $today): array
    {
        $differences = [];
        $compared = 0;

        foreach ($business->bankAccounts()->where('is_active', true)->whereNotNull('account_id')->whereHas('transactions', fn ($query) => $query->where('source', 'import'))->get() as $bankAccount) {
            $report = $this->bankReconciliationReport->generate($business, (int) $bankAccount->getKey(), $today);
            if ($report['difference'] === null) {
                continue;
            }
            $compared++;
            if ($report['difference'] !== '0.00') {
                $differences[] = ['bank_account_id' => $bankAccount->getKey(), 'bank_name' => $bankAccount->bank_name, 'difference' => $report['difference'], 'statement_balance' => $report['statement_balance'], 'expected_statement_balance' => $report['expected_statement_balance']];
            }
        }

        if ($compared === 0) {
            return $this->check('bank_differences', 'Bank differences', 'info', 'Import a bank statement with a balance (Saldo) column to compare the books with the bank.');
        }

        return $differences === []
            ? $this->check('bank_differences', 'Bank differences', 'ok', 'The books agree with the latest bank statement balance, after open items.')
            : $this->check('bank_differences', 'Bank differences', 'warning', count($differences).' bank account(s) do not agree with the statement balance after open items. Open the bank reconciliation report to find the difference.', $differences);
    }

    /** @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>} */
    private function vatStatusCheck(Business $business): array
    {
        return $business->vat_status === 'unknown'
            ? $this->check('vat_status', 'VAT registration status', 'warning', 'Set whether the business is VAT registered. Invoices cannot be created until it is set.')
            : $this->check('vat_status', 'VAT registration status', 'ok', $business->vat_status === 'registered' ? 'The business is marked as VAT registered.' : 'The business is marked as not VAT registered.');
    }

    /**
     * @param  list<string>  $mappedPurposes
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function postingAccountsCheck(Business $business, array $mappedPurposes, bool $hasActiveEmployees): array
    {
        $required = ['accounts_receivable', 'accounts_payable', 'sales_revenue', 'purchase_expense'];

        if ($business->vat_status === 'registered') {
            array_push($required, 'vat_output', 'vat_input');
        }

        if ($hasActiveEmployees) {
            array_push($required, 'salary_expense', 'employer_contribution_expense', 'net_salaries_payable', 'pension_contributions_payable', 'income_tax_payable');
        }

        $missing = array_values(array_diff($required, $mappedPurposes));

        return $missing === []
            ? $this->check('posting_accounts', 'Automatic posting accounts', 'ok', 'All accounts needed for automatic posting are mapped.')
            : $this->check('posting_accounts', 'Automatic posting accounts', 'warning', count($missing).' posting account(s) are not mapped, so invoices and payments cannot be posted automatically.', array_map(fn (string $purpose): array => ['purpose' => $purpose], $missing));
    }

    /**
     * @param  list<array{id: int, name: string, balance: string}>  $accounts
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function negativeCashCheck(array $accounts): array
    {
        return $accounts === []
            ? $this->check('negative_cash', 'Cash balances', 'ok', 'No cash account has a negative balance.')
            : $this->check('negative_cash', 'Cash balances', 'critical', count($accounts).' cash account(s) have a negative balance. Cash on hand cannot be below zero.', $accounts);
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function duplicatePurchasesCheck(array $groups): array
    {
        return $groups === []
            ? $this->check('duplicate_purchases', 'Possible duplicate supplier bills', 'ok', 'No supplier bills share the same supplier, date, and total.')
            : $this->check('duplicate_purchases', 'Possible duplicate supplier bills', 'warning', count($groups).' group(s) of supplier bills have the same supplier, date, and total. Check that none was entered twice.', $groups);
    }

    /**
     * @param  iterable<object>  $counterparties
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function missingNuiCheck(iterable $counterparties): array
    {
        $items = collect($counterparties)->map(fn (object $party): array => ['id' => (int) $party->id, 'name' => (string) $party->name])->values();

        return $items->isEmpty()
            ? $this->check('counterparty_nui', 'Client and supplier NUI', 'ok', 'Every active client and supplier has a NUI.')
            : $this->check('counterparty_nui', 'Client and supplier NUI', 'warning', $items->count().' active client(s) or supplier(s) have no NUI, which the VAT summary reports as missing.', $items->take(10)->all());
    }

    /**
     * @param  iterable<object>  $duplicates
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function duplicateNuiCheck(iterable $duplicates): array
    {
        $items = collect($duplicates)->map(fn (object $row): array => ['nui' => (string) $row->nui, 'contact_count' => (int) $row->contact_count])->values();

        return $items->isEmpty()
            ? $this->check('duplicate_nui', 'Duplicate contacts', 'ok', 'No two contacts share a NUI.')
            : $this->check('duplicate_nui', 'Duplicate contacts', 'warning', $items->count().' NUI(s) are used by more than one contact. Merge or correct them so balances are not split.', $items->take(10)->all());
    }

    /**
     * @param  array{count: int, outstanding_cents: int, examples: list<array<string, mixed>>}  $overdue
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function overdueCheck(string $key, string $label, array $overdue, string $noun): array
    {
        return $overdue['count'] === 0
            ? $this->check($key, $label, 'ok', 'Nothing is past due.')
            : $this->check($key, $label, 'warning', "{$overdue['count']} {$noun}, with ".$this->fromCents($overdue['outstanding_cents']).' outstanding.', $overdue['examples']);
    }

    /**
     * @param  iterable<object>  $examples
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function staleDraftsCheck(int $count, iterable $examples): array
    {
        $items = collect($examples)->map(fn (object $invoice): array => [
            'id' => (int) $invoice->id,
            'kind' => (string) $invoice->kind,
            'invoice_number' => (string) $invoice->invoice_number,
            'issued_on' => $invoice->issued_on instanceof \DateTimeInterface ? $invoice->issued_on->format('Y-m-d') : substr((string) $invoice->issued_on, 0, 10),
        ])->values()->all();

        return $count === 0
            ? $this->check('stale_drafts', 'Old draft invoices', 'ok', 'There are no drafts older than 14 days.')
            : $this->check('stale_drafts', 'Old draft invoices', 'warning', "{$count} draft invoice(s) are older than 14 days. Issue or remove them.", $items);
    }

    /** @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>} */
    private function bankReconciliationCheck(int $count): array
    {
        return $count === 0
            ? $this->check('bank_reconciliation', 'Bank reconciliation', 'ok', 'No bank transaction older than 30 days is waiting to be matched.')
            : $this->check('bank_reconciliation', 'Bank reconciliation', 'warning', "{$count} bank transaction(s) older than 30 days are not matched to the books.");
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function lowStockCheck(array $items): array
    {
        return $items === []
            ? $this->check('low_stock', 'Stock levels', 'ok', 'No item is below its minimum stock level.')
            : $this->check('low_stock', 'Stock levels', 'warning', count($items).' item(s) are below their minimum stock level.', array_slice($items, 0, 10));
    }

    /** @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>} */
    private function journalSourceCheck(int $count): array
    {
        return $count === 0
            ? $this->check('journal_sources', 'Source documents', 'ok', 'Every posted journal entry is linked to a document or invoice.')
            : $this->check('journal_sources', 'Source documents', 'warning', "{$count} posted journal entry(ies) have no source document.");
    }

    /** @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>} */
    private function vatThresholdCheck(Business $business, string $today, int $salesNetCents): array
    {
        $label = 'VAT registration threshold';

        if ($business->vat_status === 'registered') {
            return $this->check('vat_threshold', $label, 'info', 'Not applicable: the business is already VAT registered.');
        }

        $ruleSet = $this->complianceRuleSetRepository->findEffective('XK', 'vat', $today);
        $configuration = $ruleSet?->rules->firstWhere('rule_key', 'registration_threshold')?->configuration;

        if (! is_array($configuration) || ! isset($configuration['amount']) || ! is_numeric($configuration['amount']) || (float) $configuration['amount'] <= 0) {
            return $this->check('vat_threshold', $label, 'info', 'No registration threshold is configured in the approved VAT rules, so turnover is not checked.');
        }

        $thresholdCents = (int) round((float) $configuration['amount'] * 100);
        $warningPercent = isset($configuration['warning_percent']) && is_numeric($configuration['warning_percent']) ? (float) $configuration['warning_percent'] : 80.0;
        $usedPercent = $salesNetCents * 100 / $thresholdCents;
        $detail = 'Sales this year are '.$this->fromCents($salesNetCents).' of the '.$this->fromCents($thresholdCents).' threshold (rule set '.$ruleSet->version.').';
        $severity = match (true) {
            $usedPercent >= 100 => 'critical',
            $usedPercent >= $warningPercent => 'warning',
            default => 'ok',
        };

        return $this->check('vat_threshold', $label, $severity, $detail, [[
            'sales_net' => $this->fromCents($salesNetCents),
            'threshold' => $this->fromCents($thresholdCents),
            'used_percent' => round($usedPercent, 1),
        ]]);
    }

    /**
     * @param  iterable<object>  $overdueTasks
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function deadlinesCheck(iterable $overdueTasks, int $dueSoonCount): array
    {
        $items = collect($overdueTasks)->map(fn (object $task): array => [
            'id' => (int) $task->id,
            'kind' => (string) $task->kind,
            'title' => (string) $task->title,
            'due_on' => $task->due_on instanceof \DateTimeInterface ? $task->due_on->format('Y-m-d') : substr((string) $task->due_on, 0, 10),
        ])->values();

        if ($items->contains(fn (array $task): bool => $task['kind'] === 'tax_filing')) {
            return $this->check('deadlines', 'Tasks and deadlines', 'critical', $items->count().' task(s) are past their deadline, including tax filings.', $items->take(10)->all());
        }

        if ($items->isNotEmpty()) {
            return $this->check('deadlines', 'Tasks and deadlines', 'warning', $items->count().' task(s) are past their deadline.', $items->take(10)->all());
        }

        return $dueSoonCount > 0
            ? $this->check('deadlines', 'Tasks and deadlines', 'warning', "{$dueSoonCount} task(s) are due within 7 days.")
            : $this->check('deadlines', 'Tasks and deadlines', 'ok', 'Nothing is overdue or due within 7 days.');
    }

    /**
     * @param  iterable<object>  $overdueRequests
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function documentRequestsCheck(int $openCount, iterable $overdueRequests): array
    {
        $items = collect($overdueRequests)->map(fn (object $request): array => [
            'id' => (int) $request->id,
            'title' => (string) $request->title,
            'due_on' => $request->due_on instanceof \DateTimeInterface ? $request->due_on->format('Y-m-d') : substr((string) $request->due_on, 0, 10),
        ])->values();

        if ($items->isNotEmpty()) {
            return $this->check('document_requests', 'Requested documents', 'warning', $items->count().' requested document(s) are past their due date.', $items->take(10)->all());
        }

        return $openCount > 0
            ? $this->check('document_requests', 'Requested documents', 'info', "{$openCount} requested document(s) are still awaited.")
            : $this->check('document_requests', 'Requested documents', 'ok', 'No requested documents are missing.');
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function unusualTransactionsCheck(array $transactions): array
    {
        return $transactions === []
            ? $this->check('unusual_transactions', 'Unusual transactions', 'ok', 'No invoice or payment in the last 30 days is far above the usual amount.')
            : $this->check('unusual_transactions', 'Unusual transactions', 'warning', count($transactions).' invoice(s) or payment(s) in the last 30 days are more than five times the usual amount. Check they are correct.', $transactions);
    }

    /** @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>} */
    private function payrollCheck(string $previousMonth, int $employeeCount, ?string $payrollStatus): array
    {
        $label = 'Last month\'s payroll';

        if ($employeeCount === 0) {
            return $this->check('payroll', $label, 'ok', "No employees were working in {$previousMonth}.");
        }

        return match ($payrollStatus) {
            'approved' => $this->check('payroll', $label, 'ok', "The payroll for {$previousMonth} is approved and posted."),
            'draft' => $this->check('payroll', $label, 'warning', "The payroll for {$previousMonth} is calculated but not approved yet."),
            default => $this->check('payroll', $label, 'warning', "No payroll has been calculated for {$previousMonth} ({$employeeCount} employee(s))."),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{key: string, label: string, severity: string, detail: string, items: list<array<string, mixed>>}
     */
    private function check(string $key, string $label, string $severity, string $detail, array $items = []): array
    {
        return ['key' => $key, 'label' => $label, 'severity' => $severity, 'detail' => $detail, 'items' => array_values($items)];
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absoluteAmount = abs($amount);

        return $sign.intdiv($absoluteAmount, 100).'.'.str_pad((string) ($absoluteAmount % 100), 2, '0', STR_PAD_LEFT);
    }
}
