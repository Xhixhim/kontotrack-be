<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostingAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->resource['account'];

        return [
            'purpose' => $this->resource['purpose'],
            'label' => $this->resource['label'],
            'account_types' => $this->resource['account_types'],
            'account' => $account === null ? null : [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type,
            ],
        ];
    }
}
