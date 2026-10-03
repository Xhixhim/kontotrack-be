<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
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
            'bank_name' => fake()->company(),
            'account_name' => fake()->words(2, true),
            'iban' => 'XK05'.fake()->numerify('################'),
            'currency' => 'EUR',
            'is_active' => true,
        ];
    }
}
