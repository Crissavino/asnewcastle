<?php

namespace Database\Factories;

use App\Models\Club;
use App\Support\CurrentClub;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Movement>
 */
class MovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            // El club activo si lo hay (así el global scope lo ve); si no, uno nuevo.
            'club_id' => app(CurrentClub::class)->id() ?? Club::factory(),
            'pot' => 'cuenta',
            'kind' => 'ingreso',
            'amount_cents' => 10000,
            'occurred_on' => now()->toDateString(),
        ];
    }
}
