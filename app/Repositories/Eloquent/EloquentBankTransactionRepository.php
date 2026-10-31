<?php

namespace App\Repositories\Eloquent;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Repositories\Contracts\BankTransactionRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentBankTransactionRepository implements BankTransactionRepository
{
    /** @return LengthAwarePaginator<int, BankTransaction> */
    public function listForBankAccount(BankAccount $bankAccount): LengthAwarePaginator
    {
        return $bankAccount->transactions()->with(['journalEntry', 'document', 'account', 'counterparty'])
            ->latest('transaction_date')->latest('id')->paginate(50);
    }

    public function existingExternalIds(BankAccount $bankAccount, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        return $bankAccount->transactions()->whereIn('external_id', $externalIds)->pluck('external_id')->all();
    }

    public function createImported(BankAccount $bankAccount, array $attributes): Collection
    {
        return $bankAccount->transactions()->createMany($attributes)->load(['journalEntry', 'document']);
    }

    public function findForReconciliation(Business $business, BankAccount $bankAccount, int $transactionId): BankTransaction
    {
        return $business->bankAccounts()->whereKey($bankAccount->getKey())->firstOrFail()
            ->transactions()->whereKey($transactionId)->lockForUpdate()->with(['journalEntry', 'document'])->firstOrFail();
    }

    public function hasOtherTransactionForJournalEntry(Business $business, int $journalEntryId, int $transactionId): bool
    {
        return $business->bankAccounts()->whereHas('transactions', fn ($query) => $query
            ->where('journal_entry_id', $journalEntryId)->where('bank_transactions.id', '!=', $transactionId))->exists();
    }

    public function markReconciled(BankTransaction $transaction, JournalEntry $entry): BankTransaction
    {
        $transaction->forceFill([
            'journal_entry_id' => $entry->getKey(),
            'is_reconciled' => true,
            'reconciled_at' => now(),
        ])->save();

        return $transaction->refresh()->load(['journalEntry', 'document', 'account', 'counterparty']);
    }

    public function ledgerMovements(Business $business, BankAccount $bankAccount, ?string $from, string $to, bool $onlyUnmatched): array
    {
        if ($bankAccount->account_id === null) {
            return [];
        }

        $businessId = (int) $business->getKey();

        return DB::table('journal_lines as lines')
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'lines.journal_entry_id')->on('entries.business_id', '=', 'lines.business_id');
            })
            ->where('lines.business_id', $businessId)
            ->where('lines.account_id', $bankAccount->account_id)
            ->where('entries.status', 'posted')
            ->when($from !== null, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
            ->whereDate('entries.entry_date', '<=', $to)
            ->when($onlyUnmatched, fn ($query) => $query->whereNotExists(fn ($matched) => $matched->selectRaw('1')
                ->from('bank_transactions')
                ->where('bank_transactions.business_id', $businessId)
                ->where('bank_transactions.source', 'import')
                ->whereColumn('bank_transactions.journal_entry_id', 'entries.id')))
            ->groupBy('entries.id', 'entries.entry_date', 'entries.reference', 'entries.description')
            ->orderBy('entries.entry_date')
            ->orderBy('entries.id')
            ->selectRaw('entries.id, entries.entry_date, entries.reference, entries.description, SUM(lines.debit) - SUM(lines.credit) AS net')
            ->get()
            ->map(fn (object $row): array => [
                'journal_entry_id' => (int) $row->id,
                'entry_date' => substr((string) $row->entry_date, 0, 10),
                'reference' => $row->reference === null ? null : (string) $row->reference,
                'description' => (string) $row->description,
                'amount' => number_format((float) $row->net, 2, '.', ''),
            ])
            ->reject(fn (array $row): bool => $row['amount'] === '0.00' || $row['amount'] === '-0.00')
            ->values()
            ->all();
    }

    public function replacePaymentLine(BankTransaction $statementLine, int $journalEntryId): bool
    {
        $paymentLine = BankTransaction::query()
            ->where('business_id', $statementLine->business_id)
            ->where('bank_account_id', $statementLine->bank_account_id)
            ->where('source', 'payment')
            ->where('journal_entry_id', $journalEntryId)
            ->whereKeyNot($statementLine->getKey())
            ->lockForUpdate()
            ->first();

        if ($paymentLine === null) {
            return false;
        }

        Payment::query()->where('business_id', $statementLine->business_id)->where('bank_transaction_id', $paymentLine->getKey())
            ->update(['bank_transaction_id' => $statementLine->getKey()]);
        $paymentLine->delete();

        return true;
    }
}
