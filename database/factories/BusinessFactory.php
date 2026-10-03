<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name' => fake()->company(),
            'trade_name' => fake()->optional()->companySuffix(),
            'nui' => fake()->unique()->numerify('##########'),
            'legal_form' => fake()->randomElement(['BI', 'SHPK', 'SH.A.']),
            'vat_status' => 'unknown',
            'municipality' => fake()->city(),
            'address' => fake()->streetAddress(),
            'activity_code' => null,
            'registered_at' => fake()->date(),
            'currency' => 'EUR',
            'status' => 'active',
            'metadata' => null,
        ];
    }

    public function vatRegistered(): static
    {
        return $this->state(fn (): array => ['vat_status' => 'registered']);
    }
}
