<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'created_by_member_id' => Member::factory()->manager(),
            'kind' => 'match',
            'opponent' => fake()->company(),
            'is_home' => true,
            'starts_at' => now()->addDays(3)->setTime(11, 0),
            'venue' => 'Teren Voluntari',
            'kit' => 'home',
        ];
    }

    public function training(): static
    {
        return $this->state(fn () => [
            'kind' => 'training',
            'opponent' => null,
        ]);
    }

    /** El creador es un member existente (mismo club). */
    public function by(Member $member): static
    {
        return $this->state(fn () => [
            'club_id' => $member->club_id,
            'created_by_member_id' => $member->id,
        ]);
    }
}
