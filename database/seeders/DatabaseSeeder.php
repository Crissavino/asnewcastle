<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * El alta de clubes es manual en v1: este seeder crea el cliente cero
     * y su delegado. El teléfono sale de SEED_MANAGER_PHONE en .env.
     */
    public function run(): void
    {
        $club = Club::firstOrCreate(
            ['slug' => 'as-new-castle'],
            [
                'name' => 'A.S New Castle',
                'city' => 'Voluntari, Ilfov',
                'league' => 'Liga a V-a Ilfov',
                'crest_path' => 'img/crest.png',
                'monthly_fee_cents' => 12000,
                'currency' => 'RON',
            ],
        );

        $phone = env('SEED_MANAGER_PHONE');

        if (! $phone) {
            $this->command?->warn('SEED_MANAGER_PHONE no está en .env: no se creó el delegado.');

            return;
        }

        $manager = User::firstOrCreate(['phone' => $phone], ['locale' => 'es']);

        $club->members()->firstOrCreate(
            ['user_id' => $manager->id],
            ['role' => 'manager', 'joined_at' => now()],
        );
    }
}
