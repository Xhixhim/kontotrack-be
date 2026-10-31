<?php

namespace App\Services\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Repositories\Contracts\BankTransactionRepository;
use App\Services\Accounting\BankCategories;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation and differences report ("Raport i diferencave"): explains the gap between
 * the bank's ledger account and the bank statement balance on a date.
 *
 *   balance in the books
 *   + statement lines not yet recorded in the books (in − out)
 *   − book entries not yet on any imported statement line (in − out)
 *   = expected statement balance, compared with the statement balance.
 *
 * Book entries before the first imported statement line are treated as already in the statement's
 * opening balance.
 */
class BankReconciliationReportService
{
    public function __construct(private BankTransactionRepository $bankTransactionRepository) {}

    /** @return array<string, mixed> */
    public function generate(Business $business, int $bankAccountId, string $to, ?string $enteredStatementBalance = null): array
    {
        /** @var BankAccount|null $bankAccount */
        $bankAccount = $business->bankAccounts()->whereKey($bankAccountId)->with('account')->first();

        if ($bankAccount === null || $bankAccount->account_id === null) {
            throw ValidationException::withMessages(['bank_account_id' => ['Choose a bank account of this business that is mapped to a ledger account.']]);
        }

        $statementLines = $bankAccount->transactions()->where('source', 'import')->whereDate('transaction_date', '<=', $to);
        $firstStatementDate = (clone $statementLines)->min('transaction_date');
        $firstStatementDate = $firstStatementDate === null ? null : substr((string) $firstStatementDate, 0, 10);

        $ledgerCents = array_sum(array_map(
            fn (array $movement): int => $this->toCents($movement['amount']),
            $this->bankTransactionRepository->ledgerMovements($business, $bankAccount, null, $to, false),
        ));

        $unrecorded = (clone $statementLines)->where('is_reconciled', false)->with(['account', 'counterparty'])
            ->orderBy('transaction_date')->orderBy('id')->get()
            ->map(fn (BankTransaction $line): array => [
                'id' => (int) $line->getKey(),
                'date' => $line->transaction_date->toDateString(),
                'external_id' => $line->external_id,
                'description' => (string) $line->description,
                'amount' => $this->fromCents($this->toCents((string) $line->amount)),
                'category' => $line->category,
                'category_label' => $line->category === null ? null : (BankCategories::ALL[$line->category]['label'] ?? $line->category),
                'booked_against' => $line->counterparty?->name ?? ($line->account === null ? null : "{$line->account->code} · {$line->account->name}"),
            ])->all();

        $notOnStatement = $firstStatementDate === null ? [] : $this->bankTransactionRepository->ledgerMovements($business, $bankAccount, $firstStatementDate, $to, true);

        $latestWithBalance = (clone $statementLines)->whereNotNull('balance_after')->orderByDesc('transaction_date')->orderByDesc('id')->first();
        $statementBalance = $enteredStatementBalance !== null
            ? $this->fromCents($this->toCents($enteredStatementBalance))
            : ($latestWithBalance === null ? null : $this->fromCents($this->toCents((string) $latestWithBalance->balance_after)));

        [$unrecordedIn, $unrecordedOut] = $this->split(array_column($unrecorded, 'amount'));
        [$notOnStatementIn, $notOnStatementOut] = $this->split(array_column($notOnStatement, 'amount'));
        $expectedCents = $ledgerCents + $unrecordedIn - $unrecordedOut - $notOnStatementIn + $notOnStatementOut;
        $differenceCents = $statementBalance === null ? null : $this->toCents($statementBalance) - $expectedCents;

        return [
            'type' => 'bank_reconciliation',
            'as_of' => $to,
            'currency' => $business->currency,
            'bank_account' => [
                'id' => (int) $bankAccount->getKey(),
                'bank_name' => $bankAccount->bank_name,
                'account_name' => $bankAccount->account_name,
                'iban' => $bankAccount->iban,
                'ledger_account' => $bankAccount->account === null ? null : "{$bankAccount->account->code} · {$bankAccount->account->name}",
            ],
            'statement_from' => $firstStatementDate,
            'ledger_balance' => $this->fromCents($ledgerCents),
            'unrecorded_in' => $this->fromCents($unrecordedIn),
            'unrecorded_out' => $this->fromCents($unrecordedOut),
            'not_on_statement_in' => $this->fromCents($notOnStatementIn),
            'not_on_statement_out' => $this->fromCents($notOnStatementOut),
            'expected_statement_balance' => $this->fromCents($expectedCents),
            'statement_balance' => $statementBalance,
            'statement_balance_source' => $enteredStatementBalance !== null ? 'entered' : ($statementBalance === null ? null : 'statement'),
            'difference' => $differenceCents === null ? null : $this->fromCents($differenceCents),
            'status' => match (true) {
                $firstStatementDate === null => 'no_statement',
                $differenceCents === null => 'balance_unknown',
                $differenceCents !== 0 => 'difference',
                $unrecorded !== [] || $notOnStatement !== [] => 'open_items',
                default => 'reconciled',
            },
            'unrecorded' => $unrecorded,
            'not_on_statement' => $notOnStatement,
        ];
    }

    /**
     * @param  list<string>  $amounts
     * @return array{int, int} money in and money out, both positive
     */
    private function split(array $amounts): array
    {
        $in = 0;
        $out = 0;
        foreach ($amounts as $amount) {
            $cents = $this->toCents($amount);
            $cents >= 0 ? $in += $cents : $out -= $cents;
        }

        return [$in, $out];
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
