<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Registration>
 */
class RegistrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'club_id' => fn (array $attrs) => Member::find($attrs['member_id'])->club_id,
            'season' => config('legitimacion.season'),
            'status' => 'pendiente',
        ];
    }

    /** Ficha con todo cargado (extranjero con pasaporte). */
    public function complete(): static
    {
        return $this->state(fn () => [
            'full_name' => fake()->name(),
            'birth_date' => '1995-05-10',
            'nationality' => 'AR',
            'passport_number' => 'AAB123456',
            'passport_path' => 'legitimacion/x/passport.jpg',
            'photo_path' => 'legitimacion/x/photo.jpg',
            'id_doc_path' => 'legitimacion/x/id.jpg',
            'played_federated' => false,
            'payment_marked' => true,
            'consent_at' => now(),
            'status' => 'completo',
            'submitted_at' => now(),
            'purge_after' => now()->addDays(90),
        ]);
    }
}
