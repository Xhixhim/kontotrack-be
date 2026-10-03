<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
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
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'personal_number' => fake()->unique()->numerify('##########'),
            'position' => fake()->jobTitle(),
            'contract_type' => 'indefinite',
            'employment_start' => '2025-01-01',
            'employment_end' => null,
            'gross_salary' => '800.00',
            'is_primary_employment' => true,
            'iban' => 'XK05'.fake()->numerify('################'),
            'bank_name' => fake()->company(),
            'status' => 'active',
        ];
    }
}
