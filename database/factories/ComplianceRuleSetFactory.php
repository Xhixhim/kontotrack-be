<?php

namespace Database\Factories;

use App\Models\ComplianceRuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComplianceRuleSet>
 */
class ComplianceRuleSetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jurisdiction' => 'XK',
            'domain' => fake()->randomElement(['accounting', 'vat', 'payroll']),
            'version' => fake()->unique()->numerify('v#.#'),
            'valid_from' => now()->startOfYear()->toDateString(),
            'valid_to' => null,
            'source_title' => 'Unverified test fixture',
            'source_url' => null,
            'approval_status' => 'draft',
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    /**
     * An approved VAT rule set; attach its vat_codes rule in the test that needs specific codes.
     */
    public function approvedVat(): static
    {
        return $this->state(fn (): array => [
            'domain' => 'vat',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);
    }
}
