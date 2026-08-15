<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Due>
 */
class DueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'member_id' => Member::factory(),
            'period' => now()->startOfMonth()->toDateString(),
            'amount_cents' => 12000,
            'status' => 'pending',
            'due_date' => now()->startOfMonth()->day(20)->toDateString(),
        ];
    }

    /** La cuota de un member existente, en su club. */
    public function forMember(Member $member): static
    {
        return $this->state(fn () => [
            'club_id' => $member->club_id,
            'member_id' => $member->id,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid']);
    }
}
