<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FixedAssetResource extends JsonResource
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
            'asset_code' => $this->asset_code,
            'name' => $this->name,
            'category' => $this->category,
            'location' => $this->location,
            'acquired_on' => $this->acquired_on?->toDateString(),
            'cost' => $this->cost,
            'residual_value' => $this->residual_value,
            'opening_accumulated_depreciation' => $this->opening_accumulated_depreciation,
            'accumulated_depreciation' => $this->accumulated_depreciation,
            'book_value' => $this->book_value,
            'depreciation_method' => $this->depreciation_method,
            'useful_life_months' => $this->useful_life_months,
            'annual_rate_percent' => $this->annual_rate_percent,
            'status' => $this->status,
            'disposed_on' => $this->disposed_on?->toDateString(),
            'disposal_proceeds' => $this->disposal_proceeds,
            'document_id' => $this->document_id,
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'occurred_on' => $event->occurred_on->toDateString(),
                'details' => $event->details,
                'journal_entry_id' => $event->journal_entry_id,
                'user' => $event->user?->name,
            ])),
            'depreciation' => $this->whenLoaded('depreciationLines', fn () => $this->depreciationLines
                ->filter(fn ($line): bool => $line->run?->status === 'approved')
                ->sortBy(fn ($line): int => $line->run->period_year * 12 + $line->run->period_month)
                ->values()
                ->map(fn ($line): array => [
                    'period' => sprintf('%04d-%02d', $line->run->period_year, $line->run->period_month),
                    'amount' => $line->amount,
                    'closing_book_value' => $line->closing_book_value,
                ])),
        ];
    }
}
