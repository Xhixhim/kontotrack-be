<?php

namespace App\Repositories\Eloquent;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Repositories\Contracts\BankTransactionRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentBankTransactionRepository implements BankTransactionRepository
{
    /** @return LengthAwarePaginator<int, BankTransaction> */
    public function listForBankAccount(BankAccount $bankAccount): LengthAwarePaginator
    {
        return $bankAccount->transactions()->with(['journalEntry', 'document'])
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

        return $transaction->refresh()->load(['journalEntry', 'document']);
    }
}
