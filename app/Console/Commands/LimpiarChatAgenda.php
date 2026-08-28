<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpia solo el chat (vestuario) y la agenda (eventos), con los hijos de los
 * eventos (asistencias, votos de figura, calificaciones). NO toca la tabla de
 * posiciones, las cuotas ni los miembros. Para dejar limpio lo que se cargó
 * probando, sin borrar todo.
 *
 *   php artisan app:limpiar-chat-agenda {modo}
 *   modo=go ejecuta; cualquier otra cosa es simulacro.
 */
class LimpiarChatAgenda extends Command
{
    protected $signature = 'app:limpiar-chat-agenda {modo=dry}';

    protected $description = 'Borra el chat del vestuario y los eventos de la agenda';

    public function handle(): int
    {
        $go = $this->argument('modo') === 'go';
        $this->warn($go ? '>>> MODO GO: se ejecuta de verdad' : '>>> SIMULACRO (dry-run). Pasá "go" para ejecutar.');

        if ($go) {
            DB::beginTransaction();
        }

        try {
            // Hijos de events primero, después events; y el chat.
            foreach (['mvp_votes', 'player_ratings', 'attendances', 'events', 'messages'] as $t) {
                $n = DB::table($t)->count();
                $this->line("  borrar {$t}: {$n}");
                if ($go) {
                    DB::table($t)->delete();
                }
            }

            if ($go) {
                DB::commit();
            }
        } catch (\Throwable $e) {
            if ($go) {
                DB::rollBack();
            }
            $this->error('ERROR: '.$e->getMessage().' — se revirtió todo.');

            return self::FAILURE;
        }

        $this->info($go ? 'LISTO. Chat y agenda limpios.' : 'Fin del simulacro.');

        return self::SUCCESS;
    }
}
