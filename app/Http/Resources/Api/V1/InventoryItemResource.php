<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $onHandQuantity = (string) ($this->on_hand_quantity ?? '0.0000');

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'purchase_price' => $this->purchase_price,
            'sale_price' => $this->sale_price,
            'minimum_quantity' => $this->minimum_quantity,
            'is_active' => $this->is_active,
            'on_hand_quantity' => $onHandQuantity,
            'is_below_minimum' => $this->is_active && $this->quantityToUnits($onHandQuantity) < $this->quantityToUnits((string) $this->minimum_quantity),
            'preferred_supplier' => $this->whenLoaded('preferredSupplier', fn () => $this->preferredSupplier === null ? null : [
                'id' => $this->preferredSupplier->id,
                'name' => $this->preferredSupplier->name,
            ]),
        ];
    }

    private function quantityToUnits(string $quantity): int
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($quantity, '+-'), 2), 2, '');

        return ((int) $whole * 10_000) + (int) substr(str_pad($fraction, 4, '0'), 0, 4);
    }
}
