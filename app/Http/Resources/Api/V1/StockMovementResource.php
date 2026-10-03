<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
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
            'movement_type' => $this->movement_type,
            'quantity_delta' => $this->quantity_delta,
            'unit_cost' => $this->unit_cost,
            'occurred_on' => $this->occurred_on?->toDateString(),
            'notes' => $this->notes,
            'inventory_item' => $this->whenLoaded('inventoryItem', fn () => [
                'id' => $this->inventoryItem->id,
                'sku' => $this->inventoryItem->sku,
                'name' => $this->inventoryItem->name,
                'unit' => $this->inventoryItem->unit,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'document' => $this->whenLoaded('document', fn () => $this->document === null ? null : [
                'id' => $this->document->id,
                'original_name' => $this->document->original_name,
            ]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice === null ? null : [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'kind' => $this->invoice->kind,
            ]),
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
