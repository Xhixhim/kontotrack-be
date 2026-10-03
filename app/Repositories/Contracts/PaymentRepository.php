<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\Payment;
use Illuminate\Pagination\LengthAwarePaginator;

interface PaymentRepository
{
    /** @return LengthAwarePaginator<int, Payment> */
    public function listForBusiness(Business $business): LengthAwarePaginator;

    public function hasPaymentForJournalEntry(Business $business, int $journalEntryId): bool;

    /** @param list<array{invoice_id: int, amount: string}> $allocations */
    public function allocateToInvoices(Payment $payment, Business $business, array $allocations): void;

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): Payment;
}
