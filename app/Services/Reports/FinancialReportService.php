<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Repositories\Contracts\FinancialReportRepository;
use Illuminate\Validation\ValidationException;

class FinancialReportService
{
    public function __construct(private FinancialReportRepository $financialReportRepository) {}

    /**
     * @param  array{account_id?: int|null, cash_account_id?: int|null}  $options
     * @return array<string, mixed>
     */
    public function generate(Business $business, string $type, string $from, string $to, array $options = []): array
    {
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
                'accounts' => $this->financialReportRepository->generalLedger($business, $accountId === null ? null : [$accountId], $from, $to),
            ];
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

        if (in_array($type, ['receivable_aging', 'payable_aging'], true)) {
            return $this->financialReportRepository->generateInvoiceAging(
                $business,
                $type === 'receivable_aging' ? 'sale' : 'purchase',
                $to,
            );
        }

        if ($type === 'vat_summary') {
            return $this->financialReportRepository->generateVatSummary($business, $from, $to);
        }

        return $this->financialReportRepository->generate($business, $type, $from, $to);
    }
}
