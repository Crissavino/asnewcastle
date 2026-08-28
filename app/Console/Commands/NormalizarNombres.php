<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deja todos los nombres con cada palabra en mayúscula (User::properCase),
 * para arreglar los que se cargaron en minúscula. Idempotente: los que ya
 * están bien no cambian.
 *
 *   php artisan app:normalizar-nombres {modo}
 *   modo=go ejecuta; cualquier otra cosa es simulacro.
 */
class NormalizarNombres extends Command
{
    protected $signature = 'app:normalizar-nombres {modo=dry}';

    protected $description = 'Capitaliza la primera letra de cada palabra en los nombres';

    public function handle(): int
    {
        $go = $this->argument('modo') === 'go';
        $changed = 0;

        foreach (User::query()->whereNotNull('name')->get() as $user) {
            $fixed = User::properCase($user->name);

            if ($fixed !== $user->name) {
                $this->line("  \"{$user->name}\" -> \"{$fixed}\"");
                $changed++;
                if ($go) {
                    $user->update(['name' => $fixed]);
                }
            }
        }

        $this->info(($go ? 'CORREGIDOS' : 'A corregir').": {$changed}".($go ? '' : ' (pasá "go" para aplicar)'));

        return self::SUCCESS;
    }
}
