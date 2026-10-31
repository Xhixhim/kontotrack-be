<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Accounting\BankCategories;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankCategorizationRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'match_text' => $this->match_text,
            'direction' => $this->direction,
            'category' => $this->category,
            'category_label' => BankCategories::ALL[$this->category]['label'] ?? $this->category,
            'is_active' => $this->is_active,
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : ['id' => $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name]),
            'counterparty' => $this->whenLoaded('counterparty', fn () => $this->counterparty === null ? null : ['id' => $this->counterparty->id, 'name' => $this->counterparty->name]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
