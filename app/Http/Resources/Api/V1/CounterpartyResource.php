<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounterpartyResource extends JsonResource
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
            'type' => $this->type,
            'name' => $this->name,
            'nui' => $this->nui,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'municipality' => $this->municipality,
            'country_code' => $this->country_code,
            'status' => $this->status,
        ];
    }
}
