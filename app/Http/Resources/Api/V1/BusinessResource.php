<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
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
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'nui' => $this->nui,
            'legal_form' => $this->legal_form,
            'owner_name' => $this->owner_name,
            'vat_status' => $this->vat_status,
            'municipality' => $this->municipality,
            'address' => $this->address,
            'activity_code' => $this->activity_code,
            'registered_at' => $this->registered_at?->toDateString(),
            'email' => $this->email,
            'phone' => $this->phone,
            'currency' => $this->currency,
            'role' => $this->whenLoaded('users', fn () => $this->users->first()?->pivot?->role),
        ];
    }
}
