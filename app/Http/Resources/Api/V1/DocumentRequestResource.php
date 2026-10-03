<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRequestResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'due_on' => $this->due_on?->toDateString(),
            'status' => $this->status,
            'requested_by' => $this->requester?->name,
            'submitted_by' => $this->submitter?->name,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'document' => $this->document === null ? null : ['id' => $this->document->id, 'original_name' => $this->document->original_name, 'category' => $this->document->category],
        ];
    }
}
