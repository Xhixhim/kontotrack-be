<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Repositories\Contracts\InvoiceRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EloquentInvoiceRepository implements InvoiceRepository
{
    /** @return LengthAwarePaginator<int, Invoice> */
    public function listForBusiness(Business $business, ?string $kind = null): LengthAwarePaginator
    {
        return $business->invoices()
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->with(['counterparty', 'document', 'lines', 'journalEntry', 'payments'])
            ->latest('issued_on')
            ->latest('id')
            ->paginate(50);
    }

    public function findForDisplay(Business $business, int $invoiceId): Invoice
    {
        return $business->invoices()
            ->whereKey($invoiceId)
            ->with(['counterparty', 'document', 'lines', 'journalEntry', 'payments'])
            ->firstOrFail();
    }

    public function invoiceNumberExists(Business $business, string $kind, string $invoiceNumber): bool
    {
        return $business->invoices()
            ->where('kind', $kind)
            ->where('invoice_number', $invoiceNumber)
            ->exists();
    }

    public function nextSalesInvoiceNumber(Business $business, int $calendarYear): string
    {
        // Lock the parent row so creation of a business/year sequence is safe under concurrency.
        Business::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();

        $sequence = DB::table('business_invoice_sequences')
            ->where('business_id', $business->getKey())
            ->where('calendar_year', $calendarYear)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            $sequenceId = DB::table('business_invoice_sequences')->insertGetId([
                'business_id' => $business->getKey(),
                'calendar_year' => $calendarYear,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = DB::table('business_invoice_sequences')->where('id', $sequenceId)->lockForUpdate()->first();
        }

        $nextNumber = (int) $sequence->last_number + 1;

        while ($business->invoices()->where('kind', 'sale')->where('invoice_number', sprintf('%04d-%06d', $calendarYear, $nextNumber))->exists()) {
            $nextNumber++;

            if ($nextNumber > 4_294_967_295) {
                throw ValidationException::withMessages(['issued_on' => ['The sales invoice sequence has reached its limit for this year.']]);
            }
        }

        DB::table('business_invoice_sequences')->where('id', $sequence->id)->update([
            'last_number' => $nextNumber,
            'updated_at' => now(),
        ]);

        return sprintf('%04d-%06d', $calendarYear, $nextNumber);
    }

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(Business $business, array $attributes, array $lines): Invoice
    {
        $invoice = $business->invoices()->create($attributes);
        $invoice->lines()->createMany($lines);

        return $invoice->load(['counterparty', 'document', 'lines']);
    }

    public function findForIssuing(Business $business, int $invoiceId): Invoice
    {
        return $business->invoices()
            ->whereKey($invoiceId)
            ->lockForUpdate()
            ->with(['counterparty', 'document', 'lines'])
            ->firstOrFail();
    }

    public function markIssued(Invoice $invoice, JournalEntry $journalEntry, User $user): Invoice
    {
        $invoice->forceFill([
            'status' => 'issued',
            'journal_entry_id' => $journalEntry->getKey(),
            'issued_by' => $user->getKey(),
            'issued_at' => now(),
        ])->save();

        return $invoice->refresh()->load(['counterparty', 'document', 'lines', 'journalEntry']);
    }

    /** @param array{cancelled_on: string, cancellation_reason: string} $attributes */
    public function markCancelled(Invoice $invoice, JournalEntry $reversalEntry, User $user, array $attributes): Invoice
    {
        $invoice->forceFill([
            'status' => 'cancelled',
            'cancelled_on' => $attributes['cancelled_on'],
            'cancelled_at' => now(),
            'cancelled_by' => $user->getKey(),
            'cancellation_reason' => $attributes['cancellation_reason'],
            'cancellation_journal_entry_id' => $reversalEntry->getKey(),
        ])->save();

        return $invoice->refresh()->load(['counterparty', 'document', 'lines', 'journalEntry']);
    }

    public function findForPayment(Business $business, int $invoiceId): Invoice
    {
        return $business->invoices()
            ->whereKey($invoiceId)
            ->lockForUpdate()
            ->with('payments')
            ->firstOrFail();
    }

    public function updatePaymentStatus(Invoice $invoice, string $status): Invoice
    {
        $invoice->forceFill(['status' => $status])->save();

        return $invoice->refresh();
    }
}
