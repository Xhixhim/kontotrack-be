<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemUserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'is_system_admin' => (bool) $this->is_system_admin,
            'is_active' => $this->deactivated_at === null,
            'has_signed_in' => $this->email_verified_at !== null,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'businesses_count' => $this->whenCounted('businesses'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
