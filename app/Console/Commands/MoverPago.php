<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Due;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reimputa un pago ya cobrado a otra cuota del MISMO jugador: la cuota
 * destino queda pagada y la de origen vuelve a pendiente. Sirve para
 * corregir pagos que entraron al mes en curso teniendo meses más viejos
 * impagos. No mueve plata: el pago ya entró, solo cambia a qué mes se imputa,
 * así que el saldo del club no se toca.
 */
class MoverPago extends Command
{
    protected $signature = 'app:mover-pago {pago} {due} {modo=dry}';

    protected $description = 'Reimputa un pago a otra cuota del mismo jugador (dry por defecto)';

    public function handle(): int
    {
        $apply = $this->argument('modo') === 'aplicar';

        $payment = Payment::withoutGlobalScopes()->find((int) $this->argument('pago'));

        if (! $payment) {
            $this->error('No existe el pago #'.$this->argument('pago'));

            return self::FAILURE;
        }

        $origen = Due::withoutGlobalScopes()->find($payment->due_id);
        $destino = Due::withoutGlobalScopes()->find((int) $this->argument('due'));

        if (! $origen || ! $destino) {
            $this->error('Falta la cuota de origen o la de destino.');

            return self::FAILURE;
        }

        if ($origen->id === $destino->id) {
            $this->error('El pago ya está imputado a esa cuota.');

            return self::FAILURE;
        }

        if ($origen->member_id !== $destino->member_id) {
            $this->error('Las cuotas son de jugadores distintos: no se mueve.');

            return self::FAILURE;
        }

        if ($destino->status === 'paid') {
            $this->error('La cuota destino ya está pagada.');

            return self::FAILURE;
        }

        if ($destino->payments()->exists()) {
            $this->error('La cuota destino ya tiene un pago propio.');

            return self::FAILURE;
        }

        $this->line('pago #'.$payment->id.' · '.number_format($payment->amount_cents / 100, 2));
        $this->line('  origen  cuota #'.$origen->id.' '.substr((string) $origen->period, 0, 7).' '.$origen->status.' → pending');
        $this->line('  destino cuota #'.$destino->id.' '.substr((string) $destino->period, 0, 7).' '.$destino->status.' → paid');

        if ($payment->amount_cents !== $destino->amount_cents) {
            $this->warn('  OJO: el pago ('.number_format($payment->amount_cents / 100, 2).
                ') no coincide con la cuota destino ('.number_format($destino->amount_cents / 100, 2).')');
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('DRY RUN — nada se guardó. Para aplicar: ... '.$payment->id.' '.$destino->id.' aplicar');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($payment, $origen, $destino) {
            $payment->forceFill(['due_id' => $destino->id])->save();
            $destino->forceFill(['status' => 'paid'])->save();

            // El origen solo vuelve a pendiente si se quedó sin pago propio.
            if (! $origen->payments()->exists() && $origen->status === 'paid') {
                $origen->forceFill(['status' => 'pending'])->save();
            }

            foreach ([[$origen, 'pending'], [$destino, 'paid']] as [$due, $to]) {
                AuditLog::withoutGlobalScopes()->create([
                    'club_id' => $due->club_id,
                    'actor_member_id' => null,
                    'action' => 'due.status.set',
                    'subject_type' => $due->getMorphClass(),
                    'subject_id' => $due->id,
                    'meta' => ['to' => $to, 'pot' => null, 'motivo' => 'reimputación pago #'.$payment->id],
                ]);
            }
        });

        $this->info('Hecho.');

        return self::SUCCESS;
    }
}
