<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A sales invoice or credit note sent to the customer, with the PDF attached.
 */
class InvoiceMail extends Mailable
{
    /**
     * @param  array{contents: string, filename: string}  $pdf
     */
    public function __construct(
        public Business $business,
        public Invoice $invoice,
        private array $pdf,
        public ?string $personalMessage = null,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $businessName = (string) ($this->business->trade_name ?: $this->business->legal_name);
        $document = $this->invoice->document_type === 'credit_note' ? ['Nota e kreditit', 'Credit note'] : ['Fatura', 'Invoice'];

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $businessName),
            // Replies go to the business, not to the platform's sending address.
            replyTo: $this->business->email ? [new Address($this->business->email, $businessName)] : [],
            subject: "{$document[0]} {$this->invoice->invoice_number} · {$document[1]} {$this->invoice->invoice_number} – {$businessName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice');
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf['contents'], $this->pdf['filename'])->withMime('application/pdf')];
    }
}
