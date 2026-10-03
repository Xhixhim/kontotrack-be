<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankTransactionResource extends JsonResource
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
            'transaction_date' => $this->transaction_date?->toDateString(),
            'external_id' => $this->external_id,
            'description' => $this->description,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'is_reconciled' => $this->is_reconciled,
            'reconciled_at' => $this->reconciled_at?->toISOString(),
            'journal_entry' => $this->whenLoaded('journalEntry', fn () => $this->journalEntry === null ? null : [
                'id' => $this->journalEntry->id,
                'reference' => $this->journalEntry->reference,
                'description' => $this->journalEntry->description,
                'entry_date' => $this->journalEntry->entry_date?->toDateString(),
            ]),
            'document' => $this->whenLoaded('document', fn () => $this->document === null ? null : [
                'id' => $this->document->id,
                'original_name' => $this->document->original_name,
            ]),
        ];
    }
}
