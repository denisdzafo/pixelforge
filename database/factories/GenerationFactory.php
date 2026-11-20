<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        $id = (string) Str::uuid();

        return [
            'id' => $id,
            'user_id' => User::factory(),
            'idempotency_key' => $id,
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_min_nanos' => 59_385_000,
            'estimated_expected_nanos' => 66_000_000,
            'estimated_max_nanos' => 86_500_000,
            'hold_nanos' => 86_500_000,
        ];
    }
}
