<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeriodReopenRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accounting_period_id' => $this->accounting_period_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester === null ? null : ['id' => $this->requester->id, 'name' => $this->requester->name]),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider === null ? null : ['id' => $this->decider->id, 'name' => $this->decider->name]),
            'decided_at' => $this->decided_at?->toISOString(),
            'decision_note' => $this->decision_note,
            'period_closed_at' => $this->period_closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
