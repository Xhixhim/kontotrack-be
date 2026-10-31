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

    /** @return array{balance: string, date: string} */
    public function lowestPostedBalanceFrom(Business $business, int $accountId, string $fromDate): array
    {
        $postedLines = fn () => DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('lines.business_id', $business->getKey())
            ->where('entries.business_id', $business->getKey())
            ->where('entries.status', 'posted')
            ->where('lines.account_id', $accountId);

        $balance = $this->toCents((string) ($postedLines()
            ->where('entries.entry_date', '<', $fromDate)
            ->selectRaw('CAST(COALESCE(SUM(lines.debit - lines.credit), 0) AS CHAR) as balance')
            ->value('balance') ?? '0'));
        $dailyMovements = $postedLines()
            ->where('entries.entry_date', '>=', $fromDate)
            ->groupBy('entries.entry_date')
            ->orderBy('entries.entry_date')
            ->select('entries.entry_date')
            ->selectRaw('CAST(SUM(lines.debit - lines.credit) AS CHAR) as movement')
            ->get();

        // Without movements on the date itself, its end-of-day balance is the opening balance.
        $lowest = $dailyMovements->isEmpty() || substr((string) $dailyMovements->first()->entry_date, 0, 10) !== $fromDate
            ? ['cents' => $balance, 'date' => $fromDate]
            : null;

        foreach ($dailyMovements as $day) {
            $balance += $this->toCents((string) $day->movement);

            if ($lowest === null || $balance < $lowest['cents']) {
                $lowest = ['cents' => $balance, 'date' => substr((string) $day->entry_date, 0, 10)];
            }
        }

        return ['balance' => $this->fromCents($lowest['cents']), 'date' => $lowest['date']];
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

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $amount = abs($amount);

        return $sign.intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
