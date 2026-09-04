<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\MvpVote;
use App\Models\PlayerRating;
use Illuminate\Console\Command;

/**
 * One-off: Daniel Vasile quedó como player (su alta es anterior al rol
 * entrenador) y por eso aparecía entre los que no contestaron y podía votar.
 * Lo convierte a coach, libera el dorsal y borra sus "Voy" de eventos futuros.
 */
class DanielCoach extends Command
{
    protected $signature = 'app:daniel-coach {modo=dry}';

    protected $description = 'Convierte a Daniel Vasile de player a coach (dry por defecto)';

    public function handle(): int
    {
        $member = Member::withoutGlobalScopes()
            ->whereHas('user', fn ($q) => $q->where('name', 'Daniel Vasile'))
            ->firstOrFail();

        $futureIds = Event::withoutGlobalScopes()->where('starts_at', '>', now())->pluck('id');
        $voyFuturos = Attendance::where('member_id', $member->id)->whereIn('event_id', $futureIds)->count();

        $this->line("Member {$member->id}: rol {$member->role}, dorsal ".($member->shirt_number ?? '—'));
        $this->line("Voy en eventos futuros: {$voyFuturos}");
        $this->line('Votos de figura emitidos: '.MvpVote::where('voter_member_id', $member->id)->count());
        $this->line('Calificaciones emitidas: '.PlayerRating::where('rater_member_id', $member->id)->count());

        if ($this->argument('modo') !== 'go') {
            $this->info('Dry run: no se cambió nada. Correr con "go" para aplicar.');

            return self::SUCCESS;
        }

        $member->update(['role' => 'coach', 'shirt_number' => null]);
        Attendance::where('member_id', $member->id)->whereIn('event_id', $futureIds)->delete();

        $this->info("Listo: Daniel es coach, dorsal liberado, {$voyFuturos} Voy futuros borrados.");

        return self::SUCCESS;
    }
}
