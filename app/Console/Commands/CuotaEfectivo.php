<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Due;
use App\Models\Member;
use App\Models\Movement;
use App\Services\CuotaLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off: marca la cuota del mes como pagada en efectivo por un monto
 * distinto al de la cuota (el jugador estuvo afuera parte del mes). No toca
 * el fee_type del jugador, así que el mes que viene vuelve a la cuota normal.
 *
 *   php artisan app:cuota-efectivo "Adam" 150        (dry: no cambia nada)
 *   php artisan app:cuota-efectivo "Adam" 150 go     (aplica)
 */
class CuotaEfectivo extends Command
{
    protected $signature = 'app:cuota-efectivo {nombre} {lei} {modo=dry} {--periodo=} {--pot=cash}';

    protected $description = 'Marca la cuota del mes pagada en efectivo por un monto dado (dry por defecto)';

    public function handle(): int
    {
        $lei = (int) $this->argument('lei');
        $amount = $lei * 100;
        $period = $this->option('periodo')
            ? Carbon::parse($this->option('periodo'))->startOfMonth()
            : now()->startOfMonth();

        if (! in_array($this->option('pot'), Movement::POTS, true)) {
            $this->error('--pot tiene que ser: '.implode(' o ', Movement::POTS).'.');

            return self::FAILURE;
        }

        if ($lei <= 0) {
            $this->error('El monto en lei tiene que ser mayor a cero.');

            return self::FAILURE;
        }

        $members = Member::withoutGlobalScopes()
            ->whereNull('left_at')
            ->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$this->argument('nombre').'%'))
            ->with('user')
            ->get();

        if ($members->count() !== 1) {
            $this->error("El nombre no identifica a un solo jugador ({$members->count()} coincidencias):");
            $members->each(fn ($m) => $this->line("  #{$m->id} {$m->user->name}"));

            return self::FAILURE;
        }

        $member = $members->first();
        $this->line("Jugador: #{$member->id} {$member->user->name} · tipo de cuota: {$member->fee_type}");

        $due = Due::withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->whereDate('period', $period)
            ->first();

        // La misma guarda que CuotaController::setStatus: una cuota cobrada
        // online no se marca a mano, o su plata se acredita DOS veces (una por
        // el movimiento `cuota` de acá, otra por el `payout` de Mollie).
        if ($due && $due->payments()->exists()) {
            $this->error("La cuota #{$due->id} tiene un pago online: no se toca a mano.");

            return self::FAILURE;
        }

        $periodo = $period->format('Y-m');

        if ($due) {
            $this->line("Cuota #{$due->id} de {$periodo}: {$due->status} · ".($due->amount_cents / 100).' lei');
            $this->line("Queda: paid · {$lei} lei");
        } else {
            $this->line("No hay cuota de {$periodo}: se crea una nueva, paid · {$lei} lei");
        }

        if ($this->argument('modo') !== 'go') {
            $this->info('Dry run: no se cambió nada. Correr con "go" para aplicar.');

            return self::SUCCESS;
        }

        $managerId = $member->club->members()->where('role', 'manager')->orderBy('id')->value('id');

        $pagoOnline = DB::transaction(function () use (&$due, $member, $period, $amount, $lei, $periodo, $managerId) {
            // Se vuelve a mirar bajo lock: el chequeo de arriba corrió antes del
            // dry run, y entre medio puede haber entrado el webhook de Mollie.
            // Marcarla igual acreditaría la misma cuota dos veces.
            if ($due) {
                $due = Due::withoutGlobalScopes()->whereKey($due->id)->lockForUpdate()->firstOrFail();

                if ($due->payments()->exists()) {
                    return true;
                }
            }

            $antes = $due
                ? ['status' => $due->status, 'amount_cents' => $due->amount_cents]
                : ['status' => null, 'amount_cents' => null];

            $due ??= new Due([
                'club_id' => $member->club_id,
                'member_id' => $member->id,
                'period' => $period,
                'due_date' => $period->copy()->endOfMonth(),
            ]);

            $due->forceFill(['amount_cents' => $amount, 'status' => 'paid'])->save();

            app(CuotaLedger::class)->sync($due, $this->option('pot'), $managerId);

            // Mismo rastro que el botón del delegado, más el porqué del monto
            AuditLog::withoutGlobalScopes()->create([
                'club_id' => $member->club_id,
                'actor_member_id' => $managerId,
                'action' => 'due.status.set',
                'subject_type' => $due->getMorphClass(),
                'subject_id' => $due->getKey(),
                'meta' => [
                    'from' => $antes['status'],
                    'to' => 'paid',
                    'amount_cents_from' => $antes['amount_cents'],
                    'amount_cents' => $amount,
                    'nota' => "efectivo {$lei} lei ({$periodo}): estuvo afuera parte del mes",
                    'pot' => $this->option('pot'),
                    'via' => 'consola',
                ],
            ]);

            return false;
        });

        if ($pagoOnline) {
            $this->error("La cuota #{$due->id} tiene un pago online: no se toca a mano.");

            return self::FAILURE;
        }

        $this->info("Listo: cuota de {$periodo} de {$member->user->name} marcada pagada por {$lei} lei.");

        return self::SUCCESS;
    }
}
