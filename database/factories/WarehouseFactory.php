<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->unique()->city().' Warehouse',
            'address' => fake()->optional()->address(),
            'is_default' => true,
            'is_active' => true,
        ];
    }
}
