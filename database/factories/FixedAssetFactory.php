<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\FixedAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixedAsset>
 */
class FixedAssetFactory extends Factory
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
            'asset_code' => fake()->unique()->bothify('FA-####'),
            'name' => fake()->words(2, true),
            'category' => 'Equipment',
            'location' => null,
            'acquired_on' => '2026-01-15',
            'cost' => '1200.00',
            'residual_value' => '0.00',
            'opening_accumulated_depreciation' => '0.00',
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 12,
            'annual_rate_percent' => null,
            'status' => 'active',
        ];
    }
}
