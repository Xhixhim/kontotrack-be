<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
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
            'category' => $this->category,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'document_date' => $this->document_date?->toDateString(),
            'notes' => $this->notes,
            'counterparty' => $this->whenLoaded('counterparty', fn () => $this->counterparty === null ? null : ['id' => $this->counterparty->id, 'name' => $this->counterparty->name]),
            'review_status' => $this->review_status,
            'extracted_data' => $this->extracted_data,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
        ];
    }
}
