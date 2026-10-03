<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface InvoiceRepository
{
    /** @return LengthAwarePaginator<int, Invoice> */
    public function listForBusiness(Business $business, ?string $kind = null): LengthAwarePaginator;

    public function findForDisplay(Business $business, int $invoiceId): Invoice;

    public function invoiceNumberExists(Business $business, string $kind, string $invoiceNumber): bool;

    public function nextSalesInvoiceNumber(Business $business, int $calendarYear): string;

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(Business $business, array $attributes, array $lines): Invoice;

    public function findForIssuing(Business $business, int $invoiceId): Invoice;

    public function markIssued(Invoice $invoice, JournalEntry $journalEntry, User $user): Invoice;

    /** @param array{cancelled_on: string, cancellation_reason: string} $attributes */
    public function markCancelled(Invoice $invoice, JournalEntry $reversalEntry, User $user, array $attributes): Invoice;

    public function findForPayment(Business $business, int $invoiceId): Invoice;

    public function updatePaymentStatus(Invoice $invoice, string $status): Invoice;
}
