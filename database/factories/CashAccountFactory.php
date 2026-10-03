<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CashAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashAccount>
 */
class CashAccountFactory extends Factory
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
            'account_id' => null,
            'name' => fake()->unique()->words(2, true),
            'currency' => 'EUR',
            'is_active' => true,
        ];
    }
}
