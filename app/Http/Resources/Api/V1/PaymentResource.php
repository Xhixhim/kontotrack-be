<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'paid_on' => $this->paid_on?->toDateString(),
            'direction' => $this->direction,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'document' => $this->whenLoaded('document', fn () => $this->document === null ? null : [
                'id' => $this->document->id,
                'category' => $this->document->category,
                'original_name' => $this->document->original_name,
            ]),
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount === null ? null : [
                'id' => $this->cashAccount->id,
                'name' => $this->cashAccount->name,
                'account' => $this->cashAccount->account?->only(['id', 'code', 'name']),
            ]),
            'bank_account' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount === null ? null : [
                'id' => $this->bankAccount->id,
                'bank_name' => $this->bankAccount->bank_name,
                'account_name' => $this->bankAccount->account_name,
                'account' => $this->bankAccount->account?->only(['id', 'code', 'name']),
            ]),
            'journal_entry' => $this->whenLoaded('journalEntry', fn () => $this->journalEntry?->only(['id', 'entry_date', 'reference', 'description', 'status'])),
            'invoices' => $this->whenLoaded('invoices', fn () => $this->invoices->map(fn ($invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'kind' => $invoice->kind,
                'status' => $invoice->status,
                'allocated_amount' => $invoice->pivot->amount,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
