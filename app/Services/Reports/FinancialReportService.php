<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Repositories\Contracts\FinancialReportRepository;
use App\Services\Units\BusinessUnitService;
use Illuminate\Validation\ValidationException;

class FinancialReportService
{
    public function __construct(
        private FinancialReportRepository $financialReportRepository,
        private BankReconciliationReportService $bankReconciliationReport,
        private OperationalReportService $operationalReport,
        private ReportTableBuilder $tableBuilder,
        private BusinessUnitService $unitService,
    ) {}

    /**
     * @param  array{account_id?: int|null, cash_account_id?: int|null, bank_account_id?: int|null, statement_balance?: string|null, counterparty_id?: int|null, warehouse_id?: int|null, category?: string|null, group_by?: string|null, business_unit_id?: int|null}  $options
     * @return array<string, mixed>
     */
    public function generate(Business $business, string $type, string $from, string $to, array $options = []): array
    {
        $unit = $this->unitService->assertBelongs($business, $options['business_unit_id'] ?? null);
        $unitId = $unit?->getKey();

        if (in_array($type, OperationalReportService::TYPES, true)) {
            $report = $this->operationalReport->generate($business, $type, $from, $to, $options);

            // These reports are shown as plain tables, so the screen uses the same layout as the exports.
            return [...$report, 'table' => ['title' => ReportTableBuilder::TITLES[$type], 'sections' => $this->tableBuilder->sections($type, $report)]];
        }

        if ($type === 'general_ledger') {
            $accountId = $options['account_id'] ?? null;

            if ($accountId !== null && ! $business->accounts()->whereKey($accountId)->exists()) {
                throw ValidationException::withMessages(['account_id' => ['Choose an account from this business.']]);
            }

            return [
                'type' => 'general_ledger',
                'from' => $from,
                'to' => $to,
                'currency' => $business->currency,
                'business_unit' => $unit?->only(['id', 'name']),
                'accounts' => $this->financialReportRepository->generalLedger($business, $accountId === null ? null : [$accountId], $from, $to, $unitId),
            ];
        }

        if ($type === 'bank_reconciliation') {
            return $this->bankReconciliationReport->generate($business, (int) ($options['bank_account_id'] ?? 0), $to, $options['statement_balance'] ?? null);
        }

        if ($type === 'cash_book') {
            $cashAccount = $business->cashAccounts()->whereKey($options['cash_account_id'] ?? 0)->first();

            if ($cashAccount === null || $cashAccount->account_id === null) {
                throw ValidationException::withMessages(['cash_account_id' => ['Choose a cash account of this business that is mapped to a ledger account.']]);
            }

            $card = $this->financialReportRepository->generalLedger($business, [(int) $cashAccount->account_id], $from, $to)[0] ?? null;

            return [
                'type' => 'cash_book',
                'from' => $from,
                'to' => $to,
                'currency' => $business->currency,
                'cash_account' => ['id' => $cashAccount->id, 'name' => $cashAccount->name],
                'opening_balance' => $card['opening_balance'] ?? '0.00',
                'money_in' => $card['debit_total'] ?? '0.00',
                'money_out' => $card['credit_total'] ?? '0.00',
                'closing_balance' => $card['closing_balance'] ?? '0.00',
                'lines' => array_map(fn (array $line): array => [
                    'date' => $line['date'],
                    'journal_entry_id' => $line['journal_entry_id'],
                    'reference' => $line['reference'],
                    'description' => $line['description'],
                    'counterparty_name' => $line['counterparty_name'],
                    'money_in' => $line['debit'],
                    'money_out' => $line['credit'],
                    'balance' => $line['balance'],
                ], $card['lines'] ?? []),
            ];
        }

        $counterpartyId = $options['counterparty_id'] ?? null;

        if ($counterpartyId !== null && ! $business->counterparties()->whereKey($counterpartyId)->exists()) {
            throw ValidationException::withMessages(['counterparty_id' => ['Choose a customer or supplier of this business.']]);
        }

        if (in_array($type, ['receivable_aging', 'payable_aging'], true)) {
            $report = $this->financialReportRepository->generateInvoiceAging(
                $business,
                $type === 'receivable_aging' ? 'sale' : 'purchase',
                $to,
            );

            if ($counterpartyId === null) {
                return $report;
            }

            $rows = array_values(array_filter($report['rows'], fn (array $row): bool => (int) $row['counterparty_id'] === $counterpartyId));

            return [...$report, 'rows' => $rows, 'totals' => $rows === [] ? array_map(fn (mixed $total): mixed => is_int($total) ? 0 : '0.00', $report['totals']) : array_intersect_key($rows[0], $report['totals'])];
        }

        if ($type === 'vat_summary') {
            return $this->financialReportRepository->generateVatSummary($business, $from, $to);
        }

        return [...$this->financialReportRepository->generate($business, $type, $from, $to, $unitId), 'business_unit' => $type === 'balance_sheet' ? null : $unit?->only(['id', 'name'])];
    }
}
