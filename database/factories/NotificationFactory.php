<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Member;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'member_id' => Member::factory(),
            'type' => 'event',
            'body_key' => 'notifications.event_new_match',
            'body_params' => ['opponent' => fake()->company()],
            'url' => '/agenda',
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }
}
