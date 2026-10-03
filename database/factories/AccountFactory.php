<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
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
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'account_type' => fake()->randomElement(['asset', 'liability', 'equity', 'revenue', 'expense']),
            'normal_balance' => fake()->randomElement(['debit', 'credit']),
            'parent_id' => null,
            'is_system' => false,
            'is_active' => true,
            'metadata' => null,
        ];
    }

    /**
     * Use the given account type with its natural balance side.
     */
    public function ofType(string $accountType): static
    {
        return $this->state(fn (): array => [
            'account_type' => $accountType,
            'normal_balance' => in_array($accountType, ['asset', 'expense'], true) ? 'debit' : 'credit',
        ]);
    }
}
