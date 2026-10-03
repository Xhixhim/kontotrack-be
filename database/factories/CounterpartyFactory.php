<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Counterparty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Counterparty>
 */
class CounterpartyFactory extends Factory
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
            'type' => fake()->randomElement(['customer', 'supplier', 'both']),
            'name' => fake()->company(),
            'nui' => fake()->optional()->numerify('##########'),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'municipality' => fake()->city(),
            'country_code' => 'XK',
            'status' => 'active',
            'metadata' => null,
        ];
    }

    public function customer(): static
    {
        return $this->state(fn (): array => ['type' => 'customer']);
    }

    public function supplier(): static
    {
        return $this->state(fn (): array => ['type' => 'supplier']);
    }
}
