<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
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
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'iban' => $this->iban,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'balance' => $this->balance ?? '0.00',
            'unreconciled_transactions_count' => $this->unreconciled_transactions_count ?? 0,
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->id,
                'code' => $this->account->code,
                'name' => $this->account->name,
            ]),
        ];
    }
}
