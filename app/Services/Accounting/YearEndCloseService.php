<?php

namespace App\Services\Accounting;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a calendar year: one journal entry on 31 December brings every revenue and expense account
 * back to zero and moves the year's profit or loss into retained earnings.
 */
class YearEndCloseService
{
    public const ENTRY_TYPE = 'year_end_closing';

    public function __construct(private JournalEntryService $journalEntryService, private PostingAccountService $postingAccountService) {}

    /**
     * What closing the year would do, and what stands in the way.
     *
     * @return array{year: int, revenue: string, expenses: string, result: string, closed: bool, closing_entry_id: int|null, blockers: list<string>, accounts: list<array{code: string, name: string, account_type: string, balance: string}>}
     */
    public function preview(Business $business, int $year): array
    {
        $balances = $this->resultAccountBalances($business, $year);
        $revenueCents = (int) $balances->where('account_type', 'revenue')->sum('net_credit_cents');
        $expenseCents = (int) -$balances->where('account_type', 'expense')->sum('net_credit_cents');
        $closingEntry = $this->closingEntry($business, $year);
        $blockers = [];

        if ($closingEntry !== null) {
            $blockers[] = "The year {$year} is already closed. Reverse the closing entry first if it has to be redone.";
        }

        if ("{$year}-12-31" > today()->toDateString()) {
            $blockers[] = "The year {$year} has not ended yet.";
        }

        $drafts = $business->journalEntries()->where('status', 'draft')->whereDate('entry_date', '>=', "{$year}-01-01")->whereDate('entry_date', '<=', "{$year}-12-31")->count();

        if ($drafts > 0) {
            $blockers[] = "{$drafts} draft journal entr".($drafts === 1 ? 'y is' : 'ies are')." dated in {$year}. Post or delete them first.";
        }

        $draftInvoices = $business->invoices()->where('status', 'draft')->whereDate('issued_on', '>=', "{$year}-01-01")->whereDate('issued_on', '<=', "{$year}-12-31")->count();

        if ($draftInvoices > 0) {
            $blockers[] = "{$draftInvoices} draft invoice(s) are dated in {$year}. Issue or delete them first.";
        }

        if (! $business->postingAccounts()->where('purpose', 'retained_earnings')->exists()) {
            $blockers[] = 'Map the retained earnings account under Ledger → Posting accounts.';
        }

        if ($this->journalEntryService->findOpenPeriodIdForDate($business, "{$year}-12-31") === null) {
            $blockers[] = "There is no open accounting period that contains 31.12.{$year}. Close the year before closing its last period, or ask for that period to be reopened.";
        }

        if ($closingEntry === null && $balances->every(fn (object $account): bool => $account->net_credit_cents === 0)) {
            $blockers[] = "No revenue or expenses are posted in {$year}, so there is nothing to close.";
        }

        return [
            'year' => $year,
            'revenue' => $this->fromCents($revenueCents),
            'expenses' => $this->fromCents($expenseCents),
            'result' => $this->fromCents($revenueCents - $expenseCents),
            'closed' => $closingEntry !== null,
            'closing_entry_id' => $closingEntry?->getKey(),
            'blockers' => $blockers,
            'accounts' => $balances
                ->filter(fn (object $account): bool => $account->net_credit_cents !== 0)
                ->map(fn (object $account): array => [
                    'code' => (string) $account->code,
                    'name' => (string) $account->name,
                    'account_type' => (string) $account->account_type,
                    'balance' => $this->fromCents($account->account_type === 'revenue' ? $account->net_credit_cents : -$account->net_credit_cents),
                ])
                ->values()
                ->all(),
        ];
    }

    public function close(Business $business, User $user, int $year): JournalEntry
    {
        return DB::transaction(function () use ($business, $user, $year): JournalEntry {
            $preview = $this->preview($business, $year);

            if ($preview['blockers'] !== []) {
                throw ValidationException::withMessages(['year' => $preview['blockers']]);
            }

            $retainedEarnings = $this->postingAccountService->requireAccounts($business, ['retained_earnings'])['retained_earnings'];
            $lines = [];
            $resultCents = 0;

            foreach ($this->resultAccountBalances($business, $year) as $account) {
                if ($account->net_credit_cents === 0) {
                    continue;
                }

                // An account with a credit balance is debited by the same amount, and the other way round.
                $resultCents += $account->net_credit_cents;
                $lines[] = [
                    'account_id' => (int) $account->id,
                    'description' => "Mbyllja e vitit {$year} · Year-end close {$year}",
                    'debit' => $this->fromCents(max(0, $account->net_credit_cents)),
                    'credit' => $this->fromCents(max(0, -$account->net_credit_cents)),
                ];
            }

            if ($resultCents !== 0) {
                $lines[] = [
                    'account_id' => (int) $retainedEarnings->getKey(),
                    'description' => ($resultCents > 0 ? 'Fitimi i vitit' : 'Humbja e vitit')." {$year} · ".($resultCents > 0 ? 'Profit' : 'Loss')." for {$year}",
                    'debit' => $this->fromCents(max(0, -$resultCents)),
                    'credit' => $this->fromCents(max(0, $resultCents)),
                ];
            }

            $entry = $this->journalEntryService->createDraft($business, $user, [
                'accounting_period_id' => $this->journalEntryService->findOpenPeriodIdForDate($business, "{$year}-12-31"),
                'entry_date' => "{$year}-12-31",
                'reference' => "CLOSE-{$year}",
                'description' => "Mbyllja e vitit {$year} · Year-end close {$year}",
                'entry_type' => self::ENTRY_TYPE,
                'lines' => $lines,
            ]);
            $entry = $this->journalEntryService->post($business, $user, (int) $entry->getKey());
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'year_end.closed',
                'subject_type' => JournalEntry::class,
                'subject_id' => $entry->getKey(),
                'new_values' => ['year' => $year, 'revenue' => $preview['revenue'], 'expenses' => $preview['expenses'], 'result' => $preview['result']],
            ]);

            return $entry;
        });
    }

    /**
     * The posted closing entry of the year that has not been reversed.
     */
    private function closingEntry(Business $business, int $year): ?JournalEntry
    {
        return $business->journalEntries()
            ->where('entry_type', self::ENTRY_TYPE)
            ->where('status', 'posted')
            ->whereNull('reversal_of_id')
            ->whereDate('entry_date', "{$year}-12-31")
            ->whereNotIn('id', $business->journalEntries()->whereNotNull('reversal_of_id')->where('status', 'posted')->select('reversal_of_id'))
            ->first();
    }

    /**
     * Net balance of every revenue and expense account from the year's ordinary posted entries.
     *
     * @return Collection<int, object{id: int, code: string, name: string, account_type: string, net_credit_cents: int}>
     */
    private function resultAccountBalances(Business $business, int $year): Collection
    {
        return DB::table('journal_lines as lines')
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'lines.journal_entry_id')->on('entries.business_id', '=', 'lines.business_id');
            })
            ->join('accounts', function ($join): void {
                $join->on('accounts.id', '=', 'lines.account_id')->on('accounts.business_id', '=', 'lines.business_id');
            })
            ->where('lines.business_id', (int) $business->getKey())
            ->where('entries.status', 'posted')
            ->where('entries.entry_type', '!=', self::ENTRY_TYPE)
            ->whereDate('entries.entry_date', '>=', "{$year}-01-01")
            ->whereDate('entries.entry_date', '<=', "{$year}-12-31")
            ->whereIn('accounts.account_type', ['revenue', 'expense'])
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.account_type, SUM(lines.credit) AS credit_total, SUM(lines.debit) AS debit_total')
            ->get()
            ->map(function (object $account): object {
                $account->net_credit_cents = $this->toCents((string) $account->credit_total) - $this->toCents((string) $account->debit_total);

                return $account;
            });
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
