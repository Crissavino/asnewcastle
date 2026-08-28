<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Due;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza de producción para el lanzamiento: borra toda la data de
 * actividad (eventos, mensajes, votos, calificaciones, asistencias, gastos,
 * cuotas y pagos) dejando el club y los miembros. Además:
 *   - corrige el nombre del member {andres_id},
 *   - deja oculto + manager al member {appreview_id} (cuenta de revisión),
 *   - le crea al dueño (OWNER_PHONE) su cuota de SEPTIEMBRE ya paga.
 *
 * NO toca las fichas de legitimación (registrations): son datos reales de
 * la Federación. NO toca Mollie (la suscripción se mueve aparte).
 *
 * Positional args para esquivar el autocomplete de flags de Forge:
 *   php artisan app:limpiar-lanzamiento {andres_id} {appreview_id} {modo}
 *   modo=go ejecuta; cualquier otra cosa es simulacro (dry-run).
 */
class LimpiarLanzamiento extends Command
{
    protected $signature = 'app:limpiar-lanzamiento {andres_id} {appreview_id} {modo=dry}';

    protected $description = 'Limpia la data de actividad y deja el club listo para lanzar';

    public function handle(): int
    {
        $go = $this->argument('modo') === 'go';
        $andresId = (int) $this->argument('andres_id');
        $appReviewId = (int) $this->argument('appreview_id');

        $this->warn($go ? '>>> MODO GO: se ejecuta de verdad' : '>>> SIMULACRO (dry-run): no se toca nada. Pasá "go" como 3er argumento para ejecutar.');
        $this->newLine();

        // --- 1. Data de actividad a borrar (hijos antes que padres por las FKs)
        $tables = ['payments', 'dues', 'mvp_votes', 'player_ratings', 'attendances', 'events', 'messages', 'expenses'];
        foreach ($tables as $t) {
            $n = DB::table($t)->count();
            $this->line("  borrar {$t}: {$n}");
            if ($go) {
                DB::table($t)->delete();
            }
        }
        $this->line('  clubs.standings_json -> null');
        if ($go) {
            Club::query()->update(['standings_json' => null]);
        }

        // --- 2. Corregir el nombre de Andrés
        $andres = Member::withoutGlobalScopes()->with('user')->find($andresId);
        if ($andres?->user) {
            $this->line("  nombre member #{$andresId}: \"{$andres->user->name}\" -> \"Fabian Andres Rodriguez Rodriguez\"");
            if ($go) {
                $andres->user->update(['name' => 'Fabian Andres Rodriguez Rodriguez']);
            }
        } else {
            $this->error("  member #{$andresId} (andres) no encontrado");
        }

        // --- 3. App Review: oculto + manager (ve todo, no lo ve nadie)
        $appReview = Member::withoutGlobalScopes()->with('user')->find($appReviewId);
        if ($appReview) {
            $this->line("  member #{$appReviewId} (\"{$appReview->user?->name}\"): hidden=true, role=manager");
            if ($go) {
                $appReview->update(['hidden' => true, 'role' => 'manager']);
            }
        } else {
            $this->error("  member #{$appReviewId} (app review) no encontrado");
        }

        // --- 4. Cuota de septiembre PAGA para el dueño
        $ownerPhone = config('app.owner_phone');
        $owner = Member::withoutGlobalScopes()->whereHas('user', fn ($q) => $q->where('phone', $ownerPhone))->with(['user', 'club'])->first();
        if ($owner) {
            $amount = $owner->subscribedFeeCents() ?? $owner->monthlyFeeCents() ?? 0;
            $this->line("  cuota SEP 2026 PAGA para el dueño member #{$owner->id} ({$owner->user?->name}): {$amount} cents");
            if ($go) {
                Due::withoutGlobalScopes()->updateOrCreate(
                    ['club_id' => $owner->club_id, 'member_id' => $owner->id, 'period' => '2026-09-01'],
                    ['amount_cents' => $amount, 'status' => 'paid', 'due_date' => '2026-09-20'],
                );
            }
        } else {
            $this->error("  dueño (OWNER_PHONE={$ownerPhone}) no encontrado");
        }

        $this->newLine();
        $this->info($go ? 'LISTO. Limpieza ejecutada.' : 'Fin del simulacro. Revisá que los ids y montos estén bien y volvé a correr con "go".');

        return self::SUCCESS;
    }
}
