<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface JournalEntryRepository
{
    /** @return LengthAwarePaginator<int, JournalEntry> */
    public function listForBusiness(Business $business, ?string $status = null): LengthAwarePaginator;

    /** @param list<int> $accountIds
     * @return array<int, string>
     */
    public function postedBalancesForAccounts(Business $business, array $accountIds): array;

    /**
     * The lowest end-of-day posted balance (debit minus credit) of an account on or after a date.
     *
     * @return array{balance: string, date: string}
     */
    public function lowestPostedBalanceFrom(Business $business, int $accountId, string $fromDate): array;

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(Business $business, array $attributes, array $lines): JournalEntry;

    public function findForPosting(Business $business, int $journalEntryId): JournalEntry;

    public function markPosted(JournalEntry $entry, User $user): JournalEntry;

    public function deleteDraft(JournalEntry $entry): void;
}
