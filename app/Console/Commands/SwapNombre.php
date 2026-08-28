<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Da vuelta el orden del nombre de un usuario: mueve la primera palabra al
 * final. Sirve para los que cargaron "Apellido Nombre" (típico rumano) y
 * quedaron con el nombre corto al revés. Deja el nombre canónico
 * "Nombre Apellido". Se apunta por un fragmento único del nombre.
 *
 *   php artisan app:swap-nombre {fragmento} {modo}
 *   modo=go ejecuta; cualquier otra cosa es simulacro.
 */
class SwapNombre extends Command
{
    protected $signature = 'app:swap-nombre {fragmento} {modo=dry}';

    protected $description = 'Da vuelta el orden del nombre (primera palabra al final)';

    public function handle(): int
    {
        $frag = $this->argument('fragmento');
        $go = $this->argument('modo') === 'go';

        $users = User::query()->where('name', 'like', '%'.$frag.'%')->get();

        if ($users->count() !== 1) {
            $this->error("Se esperaba 1 coincidencia para \"{$frag}\", hay {$users->count()}. No se toca.");
            foreach ($users as $u) {
                $this->line("  #{$u->id}: {$u->name}");
            }

            return self::FAILURE;
        }

        $user = $users->first();
        $parts = preg_split('/\s+/', trim((string) $user->name)) ?: [];

        if (count($parts) < 2) {
            $this->error("\"{$user->name}\" tiene una sola palabra: no hay nada que dar vuelta.");

            return self::FAILURE;
        }

        $first = array_shift($parts);
        $parts[] = $first;
        $new = implode(' ', $parts);

        $this->line("  #{$user->id}: \"{$user->name}\" -> \"{$new}\"");

        if ($go) {
            $user->update(['name' => $new]);
            $this->info('LISTO.');
        } else {
            $this->warn('SIMULACRO. Pasá "go" para aplicar.');
        }

        return self::SUCCESS;
    }
}
