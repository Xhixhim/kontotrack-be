<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessTask>
 */
class BusinessTaskFactory extends Factory
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
            'kind' => 'task',
            'title' => fake()->sentence(4),
            'due_on' => '2026-07-31',
            'status' => 'open',
        ];
    }
}
