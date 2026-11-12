<?php

namespace App\Console\Commands;

use App\Services\Mollie\MollieGateway;
use App\Services\PayoutLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Red de seguridad del webhook de payouts: un webhook se puede perder, así que
 * una vez por día se repasan los payouts de los últimos 30 días y cada uno pasa
 * por PayoutLedger::sync(), el MISMO camino que usa el webhook. La idempotencia
 * es una sola (la de sync), así que repasar lo ya acreditado no duplica nada.
 *
 * Corre sin request ni usuario: el club lo resuelve PayoutLedger a mano.
 */
class SincronizarPayouts extends Command
{
    protected $signature = 'payouts:sincronizar';

    protected $description = 'Repasa los payouts de Mollie de los últimos 30 días y acredita los que falten';

    public function handle(MollieGateway $mollie, PayoutLedger $ledger): int
    {
        if (! $mollie->ready()) {
            $this->warn('Mollie no está configurado: no hay nada que sincronizar.');

            return self::SUCCESS;
        }

        try {
            $payouts = $mollie->listPayouts(now()->subDays(30));
        } catch (\Throwable $e) {
            Log::warning('No se pudo listar los payouts de Mollie', [
                'codigo' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);
            $this->error('No se pudo listar los payouts de Mollie: '.$e->getMessage());

            return self::FAILURE;
        }

        $resultados = [];
        $fallidos = 0;

        foreach ($payouts as $payout) {
            try {
                $resultado = $ledger->sync($payout);
            } catch (\Throwable $e) {
                // Un payout roto no frena a los demás; se ve en el log y en el exit code.
                $fallidos++;
                Log::error('Falló el chequeo diario de un payout de Mollie', [
                    'payout' => $payout->id ?? null,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $resultados[$resultado] = ($resultados[$resultado] ?? 0) + 1;
        }

        $resumen = collect($resultados)->map(fn ($n, $r) => "$r=$n")->implode(' ') ?: 'ninguno';
        Log::info('Chequeo diario de payouts', ['revisados' => count($payouts), 'resultados' => $resultados, 'fallidos' => $fallidos]);
        $this->info('Payouts revisados: '.count($payouts).' ('.$resumen.')'.($fallidos ? " · fallidos: $fallidos" : ''));

        return $fallidos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
