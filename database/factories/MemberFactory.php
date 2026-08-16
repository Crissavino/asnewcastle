<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Member>
 */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'user_id' => User::factory(),
            'role' => 'player',
            'shirt_number' => fake()->unique()->numberBetween(1, 250),
            'position' => fake()->randomElement(['ARQ', 'DEF', 'MED', 'DEL']),
            'preferred_foot' => fake()->randomElement(['right', 'left', 'both']),
            'availability' => ['tue', 'sat'],
            'joined_at' => now(),
        ];
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => 'manager']);
    }

    /** Recién invitado: todavía no pasó por el wizard de alta. */
    public function incomplete(): static
    {
        return $this->state(fn () => [
            'shirt_number' => null,
            'position' => null,
            'preferred_foot' => null,
            'availability' => null,
        ]);
    }
}
