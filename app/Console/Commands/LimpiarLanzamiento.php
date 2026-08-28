<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Due;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza de producción para el lanzamiento. Se apunta sola (por nombre y
 * OWNER_PHONE), sin ids, porque el output de Forge no es legible. Hace:
 *   - borra toda la data de actividad (eventos, mensajes, votos,
 *     calificaciones, asistencias, gastos, cuotas y pagos) dejando club+members,
 *   - corrige el nombre duplicado de Andrés -> "Fabian Andres Rodriguez Rodriguez",
 *   - deja oculto + manager a "App Review" (cuenta de revisión),
 *   - le crea al dueño (OWNER_PHONE) su cuota de SEPTIEMBRE ya paga.
 *
 * NO toca las fichas de legitimación (registrations): datos reales de la
 * Federación. NO toca Mollie (la suscripción se mueve con mollie:resubscribe).
 *
 *   php artisan app:limpiar-lanzamiento {modo}
 *   modo=go ejecuta; cualquier otra cosa es simulacro.
 */
class LimpiarLanzamiento extends Command
{
    protected $signature = 'app:limpiar-lanzamiento {modo=dry}';

    protected $description = 'Limpia la data de actividad y deja el club listo para lanzar';

    public function handle(): int
    {
        $go = $this->argument('modo') === 'go';
        $this->warn($go ? '>>> MODO GO: se ejecuta de verdad' : '>>> SIMULACRO (dry-run). Pasá "go" para ejecutar.');

        // --- 1. Data de actividad a borrar (hijos antes que padres por las FKs)
        foreach (['payments', 'dues', 'mvp_votes', 'player_ratings', 'attendances', 'events', 'messages', 'expenses'] as $t) {
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

        // --- 2. Corregir el nombre duplicado de Andrés (por el fragmento único)
        $andres = Member::withoutGlobalScopes()->with('user')
            ->whereHas('user', fn ($q) => $q->where('name', 'like', '%Rodriguez Rodriguez Fabian%'))
            ->get();
        if ($andres->count() === 1) {
            $m = $andres->first();
            $this->line("  nombre member #{$m->id}: \"{$m->user->name}\" -> \"Fabian Andres Rodriguez Rodriguez\"");
            if ($go) {
                $m->user->update(['name' => 'Fabian Andres Rodriguez Rodriguez']);
            }
        } else {
            $this->error("  Andrés: se esperaba 1 coincidencia, hay {$andres->count()}. No se toca.");
        }

        // --- 3. App Review: oculto + manager (ve todo, no lo ve nadie)
        $appReview = Member::withoutGlobalScopes()->with('user')
            ->whereHas('user', fn ($q) => $q->where('name', 'App Review'))
            ->get();
        if ($appReview->count() === 1) {
            $m = $appReview->first();
            $this->line("  member #{$m->id} (\"App Review\"): hidden=true, role=manager");
            if ($go) {
                $m->update(['hidden' => true, 'role' => 'manager']);
            }
        } else {
            $this->error("  App Review: se esperaba 1 coincidencia, hay {$appReview->count()}. No se toca.");
        }

        // --- 4. Cuota de septiembre PAGA para el dueño
        $owner = Member::withoutGlobalScopes()->with(['user', 'club'])
            ->whereHas('user', fn ($q) => $q->where('phone', config('app.owner_phone')))
            ->first();
        if ($owner) {
            $amount = $owner->subscribedFeeCents() ?? $owner->monthlyFeeCents() ?? 0;
            $this->line("  cuota SEP 2026 PAGA para el dueño #{$owner->id} ({$owner->user?->name}): {$amount} cents");
            if ($go) {
                Due::withoutGlobalScopes()->updateOrCreate(
                    ['club_id' => $owner->club_id, 'member_id' => $owner->id, 'period' => '2026-09-01'],
                    ['amount_cents' => $amount, 'status' => 'paid', 'due_date' => '2026-09-20'],
                );
            }
        } else {
            $this->error('  dueño (OWNER_PHONE) no encontrado.');
        }

        $this->info($go ? 'LISTO. Limpieza ejecutada.' : 'Fin del simulacro.');

        return self::SUCCESS;
    }
}
