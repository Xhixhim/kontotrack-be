<?php

namespace App\Services\Accounting;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use App\Repositories\Contracts\JournalEntryRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalEntryService
{
    public function __construct(private JournalEntryRepository $journalEntryRepository) {}

    /** @return LengthAwarePaginator<int, JournalEntry> */
    public function listForBusiness(Business $business, ?string $status = null): LengthAwarePaginator
    {
        return $this->journalEntryRepository->listForBusiness($business, $status);
    }

    /** @param array<string, mixed> $attributes */
    public function createDraft(Business $business, User $user, array $attributes): JournalEntry
    {
        return DB::transaction(function () use ($business, $user, $attributes): JournalEntry {
            $period = $business->accountingPeriods()
                ->whereKey($attributes['accounting_period_id'])
                ->lockForUpdate()
                ->first();

            if ($period === null || $period->status !== 'open' || ! $this->dateIsWithinPeriod($attributes['entry_date'], $period->starts_on->toDateString(), $period->ends_on->toDateString())) {
                throw ValidationException::withMessages(['accounting_period_id' => ['Choose an open period that contains the journal date.']]);
            }

            if (isset($attributes['document_id']) && ! $business->documents()->whereKey($attributes['document_id'])->exists()) {
                throw ValidationException::withMessages(['document_id' => ['Choose a document that belongs to this business.']]);
            }

            $lines = [];
            $debitCents = 0;
            $creditCents = 0;
            $accountIds = [];

            foreach (array_values($attributes['lines']) as $line) {
                $debit = $this->toCents($line['debit']);
                $credit = $this->toCents($line['credit']);

                if (($debit > 0) === ($credit > 0)) {
                    throw ValidationException::withMessages(['lines' => ['Each line must contain a debit or a credit, but not both.']]);
                }

                $debitCents += $debit;
                $creditCents += $credit;
                $accountIds[] = (int) $line['account_id'];
                $lines[] = [
                    'business_id' => $business->getKey(),
                    'account_id' => (int) $line['account_id'],
                    'counterparty_id' => isset($line['counterparty_id']) ? (int) $line['counterparty_id'] : null,
                    'description' => $line['description'] ?? null,
                    'debit' => $this->fromCents($debit),
                    'credit' => $this->fromCents($credit),
                    'currency' => $business->currency,
                    'exchange_rate' => '1.00000000',
                ];
            }

            if ($debitCents !== $creditCents) {
                throw ValidationException::withMessages(['lines' => ['Total debits must equal total credits.']]);
            }

            if ($business->accounts()->whereIn('id', array_unique($accountIds))->where('is_active', true)->count() !== count(array_unique($accountIds))) {
                throw ValidationException::withMessages(['lines' => ['Every line must use an active account from this business.']]);
            }

            $counterpartyIds = collect($lines)->pluck('counterparty_id')->filter()->unique();

            if ($counterpartyIds->isNotEmpty() && $business->counterparties()->whereIn('id', $counterpartyIds)->count() !== $counterpartyIds->count()) {
                throw ValidationException::withMessages(['lines' => ['Every counterparty must belong to this business.']]);
            }

            unset($attributes['lines']);
            $attributes['created_by'] = $user->getKey();
            $attributes['status'] = 'draft';

            $entry = $this->journalEntryRepository->createDraft($business, $attributes, $lines);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'journal_entry.draft_created',
                'subject_type' => JournalEntry::class,
                'subject_id' => $entry->getKey(),
                'new_values' => ['entry_date' => $entry->entry_date->toDateString(), 'total' => $this->fromCents($debitCents)],
            ]);

            return $entry;
        });
    }

    public function post(Business $business, User $user, int $journalEntryId): JournalEntry
    {
        return DB::transaction(function () use ($business, $user, $journalEntryId): JournalEntry {
            $entry = $this->journalEntryRepository->findForPosting($business, $journalEntryId);

            if ($entry->status !== 'draft') {
                throw ValidationException::withMessages(['journal_entry' => ['Only draft entries can be posted.']]);
            }

            $period = $entry->accountingPeriod()->lockForUpdate()->firstOrFail();

            if ($period->status !== 'open' || ! $this->dateIsWithinPeriod($entry->entry_date->toDateString(), $period->starts_on->toDateString(), $period->ends_on->toDateString())) {
                throw ValidationException::withMessages(['accounting_period' => ['This journal entry is outside an open accounting period.']]);
            }

            if ($entry->lines->count() < 2) {
                throw ValidationException::withMessages(['lines' => ['A journal entry needs at least two lines.']]);
            }

            $accountIds = $entry->lines->pluck('account_id')->unique();

            if ($business->accounts()->whereIn('id', $accountIds)->where('is_active', true)->count() !== $accountIds->count()) {
                throw ValidationException::withMessages(['lines' => ['Every posted line must use an active account from this business.']]);
            }

            $debitCents = 0;
            $creditCents = 0;

            foreach ($entry->lines as $line) {
                $debit = $this->toCents($line->debit);
                $credit = $this->toCents($line->credit);

                if (($debit > 0) === ($credit > 0)) {
                    throw ValidationException::withMessages(['lines' => ['Each line must contain a debit or a credit, but not both.']]);
                }

                $debitCents += $debit;
                $creditCents += $credit;
            }

            if ($debitCents !== $creditCents) {
                throw ValidationException::withMessages(['lines' => ['Total debits must equal total credits.']]);
            }

            $postedEntry = $this->journalEntryRepository->markPosted($entry, $user);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'journal_entry.posted',
                'subject_type' => JournalEntry::class,
                'subject_id' => $entry->getKey(),
                'new_values' => ['total' => $this->fromCents($debitCents)],
            ]);

            return $postedEntry;
        });
    }

    public function deleteDraft(Business $business, User $user, int $journalEntryId): void
    {
        DB::transaction(function () use ($business, $user, $journalEntryId): void {
            $entry = $this->journalEntryRepository->findForPosting($business, $journalEntryId);

            if ($entry->status !== 'draft') {
                throw ValidationException::withMessages(['journal_entry' => ['Only draft entries can be removed.']]);
            }

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'journal_entry.draft_removed',
                'subject_type' => JournalEntry::class,
                'subject_id' => $entry->getKey(),
                'old_values' => ['description' => $entry->description, 'entry_date' => $entry->entry_date->toDateString()],
            ]);

            $this->journalEntryRepository->deleteDraft($entry);
        });
    }

    /** @param array{accounting_period_id: int, entry_date: string, reference?: string|null} $attributes */
    public function createReversalDraft(Business $business, User $user, int $journalEntryId, array $attributes): JournalEntry
    {
        return DB::transaction(function () use ($business, $user, $journalEntryId, $attributes): JournalEntry {
            $original = $this->journalEntryRepository->findForPosting($business, $journalEntryId);

            if ($original->status !== 'posted' || $original->reversal_of_id !== null) {
                throw ValidationException::withMessages(['journal_entry' => ['Only a posted original entry can be reversed.']]);
            }

            if ($business->journalEntries()->where('reversal_of_id', $original->getKey())->exists()) {
                throw ValidationException::withMessages(['journal_entry' => ['This journal entry already has a reversal.']]);
            }

            $period = $business->accountingPeriods()
                ->whereKey($attributes['accounting_period_id'])
                ->lockForUpdate()
                ->first();

            if ($period === null || $period->status !== 'open' || ! $this->dateIsWithinPeriod($attributes['entry_date'], $period->starts_on->toDateString(), $period->ends_on->toDateString())) {
                throw ValidationException::withMessages(['accounting_period_id' => ['Choose an open period that contains the reversal date.']]);
            }

            $lines = $original->lines->map(fn ($line): array => [
                'business_id' => $business->getKey(),
                'account_id' => $line->account_id,
                'counterparty_id' => $line->counterparty_id,
                'description' => $line->description,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'currency' => $line->currency,
                'exchange_rate' => $line->exchange_rate,
            ])->all();

            $entry = $this->journalEntryRepository->createDraft($business, [
                'accounting_period_id' => $period->getKey(),
                'entry_date' => $attributes['entry_date'],
                'reference' => $attributes['reference'] ?? 'REV-'.$original->getKey(),
                'description' => 'Reversal of journal entry #'.$original->getKey(),
                'status' => 'draft',
                'created_by' => $user->getKey(),
                'reversal_of_id' => $original->getKey(),
                'document_id' => $original->document_id,
            ], $lines);

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'journal_entry.reversal_draft_created',
                'subject_type' => JournalEntry::class,
                'subject_id' => $entry->getKey(),
                'new_values' => ['reverses_entry_id' => $original->getKey(), 'entry_date' => $entry->entry_date->toDateString()],
            ]);

            return $entry;
        });
    }

    public function findOpenPeriodIdForDate(Business $business, string $date): ?int
    {
        $periodId = $business->accountingPeriods()
            ->where('status', 'open')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->value('id');

        return $periodId === null ? null : (int) $periodId;
    }

    private function dateIsWithinPeriod(string $date, string $startsOn, string $endsOn): bool
    {
        return $date >= $startsOn && $date <= $endsOn;
    }

    private function toCents(string|int|float $amount): int
    {
        $value = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
