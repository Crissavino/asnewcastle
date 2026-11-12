<?php

namespace App\Console\Commands;

use App\Models\Movement;
use App\Services\Mollie\ClubDeLaCuenta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off: traza la línea en la arena de los dos bolsillos. Crea un saldo
 * inicial por bolsillo (cuenta y cash) del club dueño de la cuenta; a partir de ahí
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

        // Mismo resolvedor que usa el libro de payouts: la regla de "de quién es
        // la plata" vive en un solo lugar.
        [$club, $motivo] = app(ClubDeLaCuenta::class)->resolver();

        if (! $club) {
            $this->error("No se pudo resolver el club dueño de la cuenta ({$motivo}). Setear MOLLIE_CLUB_ID.");

            return self::FAILURE;
        }

        $yaTiene = Movement::withoutGlobalScopes()
            ->where('club_id', $club->id)->where('kind', 'saldo_inicial')->exists();

        if (! $yaTiene) {
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
        } else {
            $this->warn("{$club->name} (#{$club->id}): ya tiene saldo inicial, se saltea.");
        }

        if ($escritos === 0) {
            $this->error('Ningún club quedó escrito: ya tenía saldo inicial.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('Dry run: no se cambió nada. Correr con "go" para aplicar.');

            return self::SUCCESS;
        }

        $this->info("Listo: saldo inicial escrito en {$club->name}.");

        return self::SUCCESS;
    }
}
