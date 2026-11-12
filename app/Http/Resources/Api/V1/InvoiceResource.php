<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
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
            'kind' => $this->kind,
            'business_unit_id' => $this->business_unit_id,
            'invoice_number' => $this->invoice_number,
            'issued_on' => $this->issued_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
            'issued_at' => $this->issued_at,
            'last_sent_at' => $this->last_sent_at,
            'last_sent_to' => $this->last_sent_to,
            'cancelled_on' => $this->cancelled_on?->toDateString(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_journal_entry_id' => $this->cancellation_journal_entry_id,
            'compliance_rule_set_id' => $this->compliance_rule_set_id,
            'currency' => $this->currency,
            'net_amount' => $this->net_amount,
            'tax_amount' => $this->tax_amount,
            'gross_amount' => $this->gross_amount,
            'paid_amount' => $this->when($this->resource->relationLoaded('payments'), fn () => $this->amountFromCents($this->allocatedCents())),
            'outstanding_amount' => $this->when($this->resource->relationLoaded('payments'), fn () => $this->amountFromCents($this->document_type === 'credit_note' ? 0 : max(0, $this->toCents($this->gross_amount) - $this->allocatedCents() - $this->toCents((string) $this->credited_total)))),
            'document_type' => $this->document_type,
            'credited_invoice_id' => $this->credited_invoice_id,
            'credited_total' => $this->credited_total,
            'tax_snapshot' => $this->tax_snapshot,
            'notes' => $this->notes,
            'counterparty' => $this->whenLoaded('counterparty', fn () => [
                'id' => $this->counterparty->id,
                'name' => $this->counterparty->name,
                'type' => $this->counterparty->type,
                'nui' => $this->counterparty->nui,
                'address' => $this->counterparty->address,
                'municipality' => $this->counterparty->municipality,
                'email' => $this->counterparty->email,
            ]),
            'document' => $this->whenLoaded('document', fn () => $this->document === null ? null : [
                'id' => $this->document->id,
                'original_name' => $this->document->original_name,
                'mime_type' => $this->document->mime_type,
                'size_bytes' => $this->document->size_bytes,
            ]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'inventory_item_id' => $line->inventory_item_id,
                'warehouse_id' => $line->warehouse_id,
                'position' => $line->position,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'vat_code' => $line->vat_code,
                'net_amount' => $line->net_amount,
                'tax_amount' => $line->tax_amount,
                'gross_amount' => $line->gross_amount,
                'tax_snapshot' => $line->tax_snapshot,
            ])),
            'created_at' => $this->created_at,
        ];
    }

    private function allocatedCents(): int
    {
        return $this->payments->sum(fn ($payment): int => $this->toCents($payment->pivot->amount));
    }

    private function toCents(string|int|float $amount): int
    {
        $value = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');

        return ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    private function amountFromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
