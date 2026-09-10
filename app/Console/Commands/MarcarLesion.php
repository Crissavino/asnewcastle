<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;

/**
 * One-off operativo: marcar lesionado (o recuperado, con --sano) a un jugador
 * por nombre, cuando no lo hace él mismo desde su perfil. Mismo efecto que el
 * toggle de la app: su "Voy" deja de sumar al pronóstico y lleva badge 🤕.
 */
class MarcarLesion extends Command
{
    protected $signature = 'app:lesion {quien} {modo=dry} {--sano : Marcar recuperado en vez de lesionado}';

    protected $description = 'Marca lesionado (o --sano) a un jugador por nombre (dry por defecto)';

    public function handle(): int
    {
        $quien = $this->argument('quien');

        $matches = Member::query()
            ->whereNull('left_at')
            ->where('role', '!=', 'coach')
            ->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$quien}%"))
            ->with('user')
            ->get();

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty()
                ? "Nadie del plantel matchea \"{$quien}\"."
                : "\"{$quien}\" matchea a más de uno — afinar el nombre:");
            $matches->each(fn (Member $m) => $this->line("- {$m->user->name} (member {$m->id})"));

            return self::FAILURE;
        }

        $member = $matches->first();
        $sano = (bool) $this->option('sano');
        $hoy = $member->isInjured()
            ? 'lesionado desde el '.$member->injured_since->format('d.m.Y')
            : 'sano';

        $this->line("{$member->user->name} (member {$member->id}): hoy {$hoy} → ".($sano ? 'sano' : 'lesionado'));

        if ($this->argument('modo') !== 'go') {
            $this->info('Dry run: no se cambió nada. Correr con "go" para aplicar.');

            return self::SUCCESS;
        }

        $member->update(['injured_since' => $sano ? null : now()]);
        $this->info('Guardado.');

        return self::SUCCESS;
    }
}
