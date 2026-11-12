<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
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
            'accounting_period_id' => $this->accounting_period_id,
            'business_unit_id' => $this->business_unit_id,
            'entry_date' => $this->entry_date?->toDateString(),
            'reference' => $this->reference,
            'description' => $this->description,
            'status' => $this->status,
            'entry_type' => $this->entry_type ?? 'standard',
            'reversal_of_id' => $this->reversal_of_id,
            'posted_at' => $this->posted_at,
            'accounting_period' => $this->whenLoaded('accountingPeriod', fn () => [
                'id' => $this->accountingPeriod->id,
                'name' => $this->accountingPeriod->name,
                'status' => $this->accountingPeriod->status,
            ]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'account' => [
                    'id' => $line->account?->id,
                    'code' => $line->account?->code,
                    'name' => $line->account?->name,
                ],
                'counterparty_id' => $line->counterparty_id,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'currency' => $line->currency,
                'exchange_rate' => $line->exchange_rate,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
