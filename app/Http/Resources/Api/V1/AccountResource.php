<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->account_type,
            'normal_balance' => $this->normal_balance,
            'parent_id' => $this->parent_id,
            'is_active' => $this->is_active,
        ];
    }
}
