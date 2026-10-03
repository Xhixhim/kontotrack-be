<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashAccountResource extends JsonResource
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
            'name' => $this->name,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'balance' => $this->balance ?? '0.00',
            'negative_balance' => str_starts_with((string) ($this->balance ?? '0.00'), '-'),
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->id,
                'code' => $this->account->code,
                'name' => $this->account->name,
            ]),
        ];
    }
}
