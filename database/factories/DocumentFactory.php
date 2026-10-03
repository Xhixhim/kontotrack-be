<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
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
            'uploaded_by' => User::factory(),
            'category' => 'other',
            'original_name' => fake()->word().'.pdf',
            'disk' => 'private',
            'path' => 'business-documents/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 5000000),
            'sha256' => hash('sha256', fake()->uuid()),
            'review_status' => 'pending',
            'extracted_data' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ];
    }

    public function invoice(): static
    {
        return $this->state(fn (): array => ['category' => 'invoice']);
    }
}
