<?php

namespace App\Services\Invoicing;

use App\Mail\InvoiceMail;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Reports\DocumentExportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class InvoiceEmailService
{
    public function __construct(private InvoiceService $invoiceService, private DocumentExportService $exportService) {}

    /**
     * Email an issued sales invoice or credit note to the customer with the PDF attached.
     *
     * @param  array{to: string, cc?: list<string>|null, message?: string|null}  $attributes
     */
    public function send(Business $business, User $user, int $invoiceId, array $attributes): Invoice
    {
        $invoice = $this->invoiceService->findForBusiness($business, $invoiceId);

        if ($invoice->kind !== 'sale') {
            throw ValidationException::withMessages(['invoice' => ['Only sales invoices and their credit notes can be emailed to a customer.']]);
        }
        if ($invoice->status === 'draft') {
            throw ValidationException::withMessages(['invoice' => ['Issue the invoice before sending it.']]);
        }
        if ($invoice->status === 'cancelled') {
            throw ValidationException::withMessages(['invoice' => ['A cancelled invoice cannot be sent.']]);
        }

        $copies = array_values(array_unique($attributes['cc'] ?? []));

        try {
            Mail::to($attributes['to'])->cc($copies)->send(new InvoiceMail(
                $business,
                $invoice,
                $this->exportService->invoicePdf($business, $invoice),
                $attributes['message'] ?? null,
            ));
        } catch (TransportExceptionInterface $exception) {
            Log::error('Invoice email could not be sent.', ['business_id' => $business->getKey(), 'invoice_id' => $invoice->getKey(), 'error' => $exception->getMessage()]);

            throw ValidationException::withMessages(['to' => ['The email could not be sent. Check the address and the mail settings, then try again.']]);
        }

        $invoice->forceFill(['last_sent_at' => now(), 'last_sent_to' => $attributes['to']])->save();
        $business->auditLogs()->create([
            'user_id' => $user->getKey(),
            'action' => 'invoice.emailed',
            'subject_type' => Invoice::class,
            'subject_id' => $invoice->getKey(),
            'new_values' => ['invoice_number' => $invoice->invoice_number, 'to' => $attributes['to'], 'cc' => $copies],
        ]);

        return $invoice;
    }
}
