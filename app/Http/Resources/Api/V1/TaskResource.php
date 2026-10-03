<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'kind' => $this->kind,
            'obligation_type' => $this->obligation_type,
            'period_label' => $this->period_label,
            'title' => $this->title,
            'notes' => $this->notes,
            'due_on' => $this->due_on?->toDateString(),
            'status' => $this->status,
            'is_overdue' => $this->status === 'open' && $this->due_on?->toDateString() < today()->toDateString(),
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee === null ? null : ['id' => $this->assignee->id, 'name' => $this->assignee->name]),
            'completed_on' => $this->completed_on?->toDateString(),
            'reference' => $this->reference,
            'amount' => $this->amount,
            'document' => $this->whenLoaded('document', fn () => $this->document === null ? null : ['id' => $this->document->id, 'original_name' => $this->document->original_name]),
        ];
    }
}
