<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepreciationRunResource extends JsonResource
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
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'status' => $this->status,
            'total' => $this->total,
            'journal_entry_id' => $this->journal_entry_id,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'fixed_asset_id' => $line->fixed_asset_id,
                'asset_code' => $line->fixedAsset?->asset_code,
                'asset_name' => $line->fixedAsset?->name,
                'opening_book_value' => $line->opening_book_value,
                'amount' => $line->amount,
                'closing_book_value' => $line->closing_book_value,
            ])),
        ];
    }
}
