<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Club>
 */
class ClubFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'city' => fake()->city(),
            'league' => 'Liga a V-a',
            'crest_path' => null,
            'monthly_fee_cents' => 12000,
            'currency' => 'RON',
        ];
    }
}
