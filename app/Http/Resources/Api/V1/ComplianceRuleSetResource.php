<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplianceRuleSetResource extends JsonResource
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
            'jurisdiction' => $this->jurisdiction,
            'domain' => $this->domain,
            'version' => $this->version,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'source_title' => $this->source_title,
            'source_url' => $this->source_url,
            'approval_status' => $this->approval_status,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'rules' => $this->whenLoaded('rules', fn () => $this->rules->map(fn ($rule): array => [
                'id' => $rule->id,
                'rule_key' => $rule->rule_key,
                'configuration' => $rule->configuration,
                'description' => $rule->description,
            ])),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver?->only(['id', 'name'])),
            'created_at' => $this->created_at,
        ];
    }
}
