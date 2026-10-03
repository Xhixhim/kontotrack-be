<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use App\Repositories\Contracts\JournalEntryRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentJournalEntryRepository implements JournalEntryRepository
{
    /** @return LengthAwarePaginator<int, JournalEntry> */
    public function listForBusiness(Business $business, ?string $status = null): LengthAwarePaginator
    {
        return $business->journalEntries()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['accountingPeriod', 'lines.account'])
            ->latest('entry_date')
            ->latest('id')
            ->paginate(50);
    }

    /** @param list<int> $accountIds
     * @return array<int, string>
     */
    public function postedBalancesForAccounts(Business $business, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('lines.business_id', $business->getKey())
            ->where('entries.business_id', $business->getKey())
            ->where('entries.status', 'posted')
            ->whereIn('lines.account_id', $accountIds)
            ->groupBy('lines.account_id')
            ->select('lines.account_id')
            ->selectRaw('CAST(COALESCE(SUM(lines.debit - lines.credit), 0) AS CHAR) as balance')
            ->pluck('balance', 'account_id')
            ->map(fn ($balance): string => (string) $balance)
            ->all();
    }

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(Business $business, array $attributes, array $lines): JournalEntry
    {
        $entry = $business->journalEntries()->create($attributes);
        $entry->lines()->createMany($lines);

        return $entry->load(['accountingPeriod', 'lines.account']);
    }

    public function findForPosting(Business $business, int $journalEntryId): JournalEntry
    {
        return $business->journalEntries()
            ->whereKey($journalEntryId)
            ->lockForUpdate()
            ->with('lines')
            ->firstOrFail();
    }

    public function markPosted(JournalEntry $entry, User $user): JournalEntry
    {
        $entry->forceFill([
            'status' => 'posted',
            'posted_by' => $user->getKey(),
            'posted_at' => now(),
        ])->save();

        return $entry->refresh()->load(['accountingPeriod', 'lines.account']);
    }

    public function deleteDraft(JournalEntry $entry): void
    {
        $entry->delete();
    }
}
