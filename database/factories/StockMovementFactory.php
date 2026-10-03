<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $businessFactory = Business::factory();

        return [
            'business_id' => $businessFactory,
            'inventory_item_id' => InventoryItem::factory()->for($businessFactory),
            'warehouse_id' => Warehouse::factory()->for($businessFactory),
            'movement_type' => 'adjustment',
            'quantity_delta' => '1.0000',
            'occurred_on' => now()->toDateString(),
            'notes' => 'Factory stock movement',
        ];
    }
}
