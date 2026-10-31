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

    /**
     * Posted journal entries that move the bank account's ledger account, with their net movement
     * (debit minus credit) on it. With $onlyUnmatched, entries already reconciled with an imported
     * statement line are left out.
     *
     * @return list<array{journal_entry_id: int, entry_date: string, reference: string|null, description: string, amount: string}>
     */
    public function ledgerMovements(Business $business, BankAccount $bankAccount, ?string $from, string $to, bool $onlyUnmatched): array;

    /**
     * When a payment recorded in the books already created a bank line for this journal entry,
     * the imported statement line replaces it: the payment points at the statement line instead.
     */
    public function replacePaymentLine(BankTransaction $statementLine, int $journalEntryId): bool;
}
