<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Accounting\BankCategories;
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
            'balance_after' => $this->balance_after,
            'currency' => $this->currency,
            'source' => $this->source,
            'category' => $this->category,
            'category_label' => $this->category === null ? null : (BankCategories::ALL[$this->category]['label'] ?? $this->category),
            // A suggestion from a rule or from the description, not yet confirmed by a person.
            'category_is_suggestion' => $this->category !== null && $this->categorized_by === null,
            'categorization_rule_id' => $this->categorization_rule_id,
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : ['id' => $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name]),
            'counterparty' => $this->whenLoaded('counterparty', fn () => $this->counterparty === null ? null : ['id' => $this->counterparty->id, 'name' => $this->counterparty->name]),
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
