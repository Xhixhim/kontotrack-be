<?php

namespace App\Repositories\Contracts;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface BankTransactionRepository
{
    /** @return LengthAwarePaginator<int, BankTransaction> */
    public function listForBankAccount(BankAccount $bankAccount): LengthAwarePaginator;

    /** @param list<string> $externalIds
     * @return list<string>
     */
    public function existingExternalIds(BankAccount $bankAccount, array $externalIds): array;

    /** @param list<array<string, mixed>> $attributes
     * @return Collection<int, BankTransaction>
     */
    public function createImported(BankAccount $bankAccount, array $attributes): Collection;

    public function findForReconciliation(Business $business, BankAccount $bankAccount, int $transactionId): BankTransaction;

    public function hasOtherTransactionForJournalEntry(Business $business, int $journalEntryId, int $transactionId): bool;

    public function markReconciled(BankTransaction $transaction, JournalEntry $entry): BankTransaction;
}
