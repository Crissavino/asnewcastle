<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Movement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off: traza la línea en la arena de los dos bolsillos. Crea un saldo
 * inicial por bolsillo (cuenta y cash) en cada club con cuota; a partir de ahí
 * los saldos salen solos del libro. Los montos se pasan en lei y se guardan en
 * centavos. Una fila con 0 también se escribe: documenta la fecha de la línea.
 *
 * Corre sin request ni usuario, así que el global scope de club no filtra ni
 * completa club_id: el club se resuelve explícitamente y club_id va a mano.
 *
 *   php artisan app:saldo-inicial 225 0        (dry: no cambia nada)
 *   php artisan app:saldo-inicial 225 0 go     (aplica)
 */
class SaldoInicial extends Command
{
    protected $signature = 'app:saldo-inicial {cuenta_lei} {efectivo_lei} {modo=dry}';

    protected $description = 'Crea el saldo inicial de cada bolsillo por club (dry por defecto)';

    public function handle(): int
    {
        $cuenta = $this->argument('cuenta_lei');
        $efectivo = $this->argument('efectivo_lei');

        foreach (['cuenta_lei' => $cuenta, 'efectivo_lei' => $efectivo] as $nombre => $valor) {
            if (! ctype_digit((string) $valor)) {
                $this->error("{$nombre} tiene que ser un número entero de lei, cero o más (recibí \"{$valor}\").");

                return self::FAILURE;
            }
        }

        $montos = ['cuenta' => (int) $cuenta * 100, 'cash' => (int) $efectivo * 100];
        $apply = $this->argument('modo') === 'go';
        $hoy = now()->toDateString();
        $escritos = 0;

        $clubs = Club::withoutGlobalScopes()->where('monthly_fee_cents', '>', 0)->orderBy('id')->get();

        if ($clubs->isEmpty()) {
            $this->error('No hay ningún club con cuota para ponerle saldo inicial.');

            return self::FAILURE;
        }

        foreach ($clubs as $club) {
            $yaTiene = Movement::withoutGlobalScopes()
                ->where('club_id', $club->id)->where('kind', 'saldo_inicial')->exists();

            if ($yaTiene) {
                $this->warn("{$club->name} (#{$club->id}): ya tiene saldo inicial, se saltea.");

                continue;
            }

            $this->line("{$club->name} (#{$club->id}): cuenta {$cuenta} lei · efectivo {$efectivo} lei · fecha {$hoy}");

            if ($apply) {
                DB::transaction(function () use ($club, $montos, $hoy) {
                    foreach ($montos as $pot => $cents) {
                        Movement::withoutGlobalScopes()->create([
                            'club_id' => $club->id,
                            'pot' => $pot,
                            'kind' => 'saldo_inicial',
                            'amount_cents' => $cents,
                            'occurred_on' => $hoy,
                            'note' => 'Saldo inicial (línea en la arena)',
                        ]);
                    }
                });
            }

            $escritos++;
        }

        if ($escritos === 0) {
            $this->error('Ningún club quedó escrito: todos ya tenían saldo inicial.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('Dry run: no se cambió nada. Correr con "go" para aplicar.');

            return self::SUCCESS;
        }

        $this->info("Listo: saldo inicial escrito en {$escritos} club(es).");

        return self::SUCCESS;
    }
}
