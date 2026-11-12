<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Mollie\Api\MollieApiClient;

/**
 * Sonda de SOLO LECTURA de saldo y payouts de Mollie. No escribe nada (ni en la
 * base ni en Mollie). Contesta tres incógnitas, cada una con su línea:
 *   (a) permisos:  "balances.read: SÍ|NO" y "payouts.read: SÍ|NO"
 *   (b) comisión:  "COMISION: ..." (de las deductions de las balance transactions)
 *   (c) moneda:    "MONEDA PAYOUT: ..."
 * Pensado para correrse desde Forge: salida corta, sin volcados de JSON.
 */
class MollieSaldoDiag extends Command
{
    protected $signature = 'app:mollie-saldo-diag';

    protected $description = 'Sonda de solo lectura: balances, payouts y balance transactions de Mollie';

    public function handle(MollieApiClient $mollie): int
    {
        $key = (string) config('services.mollie.key');
        if ($key === '') {
            $this->error('NO HAY CREDENCIALES: MOLLIE_KEY no está configurada. No se consultó nada.');

            return self::SUCCESS;
        }

        $this->info('key prefix='.substr($key, 0, 5).' | testmode='.(int) config('services.mollie.testmode'));
        $testmode = (bool) config('services.mollie.testmode');

        // (a) balances.read
        $balances = null;
        try {
            $balances = $mollie->balances->page(null, 10, ['testmode' => $testmode]);
            $this->info('balances.read: SÍ ('.count($balances).' balances)');
            foreach ($balances as $b) {
                $this->line("  {$b->id} {$b->currency} {$b->status} disponible=".$this->money($b->availableAmount).' entrante='.$this->money($b->incomingAmount).' saliente='.$this->money($b->outgoingAmount));
            }
        } catch (\Throwable $e) {
            $this->error('balances.read: NO — '.$this->why($e));
        }

        // (a) payouts.read + (c) moneda
        $payouts = null;
        try {
            $payouts = $mollie->payouts->page(null, 5, ['testmode' => $testmode]);
            $this->info('payouts.read: SÍ ('.count($payouts).' payouts listados)');
            foreach ($payouts as $p) {
                $this->line("  {$p->id} {$p->status} ".$this->money($p->amount)." creado={$p->createdAt} completado=".($p->completedAt ?? '-')." balance={$p->balanceId}");
            }
        } catch (\Throwable $e) {
            $this->error('payouts.read: NO — '.$this->why($e));
        }

        if ($payouts !== null) {
            if (count($payouts) === 0) {
                $this->warn('MONEDA PAYOUT: SIN DATOS — todavía no hay payouts');
            } else {
                $cur = collect($payouts)->map(fn ($p) => $p->amount->currency ?? '?')->unique()->implode(',');
                $this->info('MONEDA PAYOUT: '.$cur.' ('.($cur === 'RON' ? 'SÍ es RON' : 'NO es RON').')');
            }
        }

        // (b) comisión: balance transactions del balance del último payout
        $balanceId = ($payouts !== null && count($payouts) > 0)
            ? $payouts[0]->balanceId
            : (($balances !== null && count($balances) > 0) ? $balances[0]->id : null);

        if ($balanceId === null) {
            $this->warn('COMISION: SIN DATOS — no hay balance para consultar');

            return self::SUCCESS;
        }

        try {
            $txs = $mollie->balanceTransactions->pageForId($balanceId, ['limit' => 15], $testmode);
            $this->info('balance transactions de '.$balanceId.': '.count($txs).' (más recientes primero)');
            $conDeductions = 0;
            foreach ($txs as $t) {
                $ded = '-';
                if (! empty($t->deductions)) {
                    $conDeductions++;
                    $ded = $this->money($t->deductions);
                }
                $this->line("  {$t->type} resultado=".$this->money($t->resultAmount).' inicial='.$this->money($t->initialAmount)." deductions={$ded} {$t->createdAt}");
            }
            $this->info('COMISION: '.($conDeductions > 0
                ? "SÍ viene en deductions ({$conDeductions} de ".count($txs).' transacciones las traen)'
                : 'NO hay deductions en estas transacciones — hay que derivarla (inicial - resultado)'));
        } catch (\Throwable $e) {
            $this->error('COMISION: no se pudo leer — balance transactions: '.$this->why($e));
        }

        return self::SUCCESS;
    }

    private function money($amount): string
    {
        if (is_object($amount) && isset($amount->value)) {
            return $amount->value.' '.($amount->currency ?? '?');
        }

        return $amount === null ? '-' : '?';
    }

    private function why(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($e->getCode() === 401 || stripos($msg, 'authentication') !== false) {
            return 'key inválida o sin autenticar (401), NO es falta de permiso — '.class_basename($e);
        }
        $perm = stripos($msg, 'permission') !== false || $e->getCode() === 403 || stripos($msg, 'forbidden') !== false
            ? 'no tengo ese permiso (403) — ' : '';

        return $perm.class_basename($e).': '.mb_substr($msg, 0, 160);
    }
}
