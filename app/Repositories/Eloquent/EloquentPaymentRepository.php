<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentPaymentRepository implements PaymentRepository
{
    /** @return LengthAwarePaginator<int, Payment> */
    public function listForBusiness(Business $business): LengthAwarePaginator
    {
        return $business->payments()
            ->with(['cashAccount.account', 'bankAccount.account', 'journalEntry', 'invoices', 'document'])
            ->latest('paid_on')
            ->latest('id')
            ->paginate(50);
    }

    public function hasPaymentForJournalEntry(Business $business, int $journalEntryId): bool
    {
        return $business->payments()->where('journal_entry_id', $journalEntryId)->exists();
    }

    /** @param list<array{invoice_id: int, amount: string}> $allocations */
    public function allocateToInvoices(Payment $payment, Business $business, array $allocations): void
    {
        foreach ($allocations as $allocation) {
            $payment->invoices()->attach($allocation['invoice_id'], [
                'business_id' => $business->getKey(),
                'amount' => $allocation['amount'],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): Payment
    {
        return $business->payments()->create($attributes)
            ->load(['cashAccount.account', 'bankAccount.account', 'journalEntry', 'invoices', 'document']);
    }
}
