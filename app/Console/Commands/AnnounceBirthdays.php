<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Member;
use App\Models\Message;
use Illuminate\Console\Command;

/**
 * Publica en el vestuario los cumpleaños del día. Corre a diario por el
 * scheduler; las fechas salen del birth_date que se pide en el alta.
 */
class AnnounceBirthdays extends Command
{
    protected $signature = 'cumples:anunciar';

    protected $description = 'Publica en el vestuario los cumpleaños del día';

    public function handle(): int
    {
        $today = today();
        $announced = 0;

        Club::query()->each(function (Club $club) use ($today, &$announced) {
            $club->activeMembers()
                ->whereHas('user', fn ($q) => $q
                    ->whereMonth('birth_date', $today->month)
                    ->whereDay('birth_date', $today->day))
                ->with('user:id,name,birth_date')
                ->get()
                ->each(function (Member $member) use ($club, &$announced) {
                    Message::create([
                        'club_id' => $club->id,
                        'is_system' => true,
                        'body' => json_encode([
                            'key' => 'system.birthday',
                            'params' => [
                                'name' => $member->user->name,
                                'age' => $member->user->birth_date->age,
                            ],
                        ]),
                    ]);

                    $this->info("🎂 {$member->user->name} ({$club->name})");
                    $announced++;
                });
        });

        if ($announced === 0) {
            $this->info('Hoy no cumple nadie.');
        }

        return self::SUCCESS;
    }
}
