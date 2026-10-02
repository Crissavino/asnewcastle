<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;

/**
 * Bloquea (o desbloquea, con --abrir) el reingreso de un jugador a su club.
 * Mientras esté bloqueado, joinOrRejoin no le levanta la baja: no vuelve ni
 * por el auto-join de club único ni con un link de invitación.
 *
 * El bloqueo es de la MEMBRESÍA, no del teléfono: la persona puede seguir
 * entrando a otro club donde sí la quieran. Y es una decisión aparte de la
 * baja — el que se va en buenos términos no queda bloqueado.
 *
 *   php artisan app:bloquear "Nelu" go
 *   php artisan app:bloquear "Nelu" go --abrir
 */
class BloquearReingreso extends Command
{
    protected $signature = 'app:bloquear {quien} {modo=dry} {--abrir : Desbloquear en vez de bloquear}';

    protected $description = 'Bloquea el reingreso de un jugador a su club (dry por defecto)';

    public function handle(): int
    {
        $quien = $this->argument('quien');
        $abrir = (bool) $this->option('abrir');

        // A diferencia de los otros one-off, acá NO se filtra por left_at: al
        // que querés bloquear justamente ya le diste de baja.
        $matches = Member::withoutGlobalScopes()
            ->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$quien}%"))
            ->with('user', 'club')
            ->get();

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty()
                ? "Nadie matchea \"{$quien}\"."
                : "\"{$quien}\" matchea a más de uno — afinar el nombre:");
            $matches->each(fn (Member $m) => $this->line("- {$m->user->name} (member {$m->id}, club {$m->club?->name})"));

            return self::FAILURE;
        }

        $member = $matches->first();
        $hoy = $member->blocked_at ? 'bloqueado desde el '.$member->blocked_at->format('d.m.Y') : 'sin bloqueo';
        $baja = $member->left_at ? 'de baja' : 'ACTIVO en el plantel';

        $this->line("{$member->user->name} (member {$member->id}, club {$member->club?->name})");
        $this->line("  {$baja} · {$hoy} → ".($abrir ? 'sin bloqueo' : 'bloqueado'));

        if (! $abrir && ! $member->left_at) {
            $this->warn('  Ojo: sigue activo en el plantel. Bloquear no lo saca; primero dale de baja.');
        }

        if ($this->argument('modo') !== 'go') {
            $this->info('Dry run: no se cambió nada. Correr con "go" para aplicar.');

            return self::SUCCESS;
        }

        $member->update(['blocked_at' => $abrir ? null : now()]);

        $this->info($abrir ? 'Desbloqueado: puede volver a entrar.' : 'Bloqueado: no vuelve hasta que lo abras.');

        return self::SUCCESS;
    }
}
