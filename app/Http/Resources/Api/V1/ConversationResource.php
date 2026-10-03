<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
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
            'business_id' => $this->business_id,
            'subject' => $this->subject,
            'status' => $this->status,
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
            ])),
            'updated_at' => $this->updated_at,
        ];
    }
}
