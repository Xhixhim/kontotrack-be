<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
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
            'action' => $this->action,
            'subject' => array_search($this->subject_type, AuditLogService::SUBJECTS, true) ?: null,
            'subject_id' => $this->subject_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'user' => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
