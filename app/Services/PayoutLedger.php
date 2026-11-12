<?php

namespace App\Services;

use App\Models\Club;
use App\Models\MolliePayout;
use App\Models\Movement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La única puerta por la que entra plata a la cuenta del club: el payout, o sea
 * el depósito que Mollie hace en el IBAN con lo que juntó de las cuotas.
 *
 * Nuestros `payments` son BRUTOS y el payout es NETO (la diferencia es la
 * comisión de Mollie), así que el saldo de la cuenta no se puede derivar de los
 * pagos: sale de acá.
 *
 * Es idempotente y reusable: el webhook y el chequeo diario pasan por el mismo
 * `sync()`, así que la idempotencia es una sola. Corre sin request ni usuario,
 * por lo tanto el global scope de club no filtra ni completa club_id: el club se
 * resuelve explícitamente y club_id va a mano, siempre.
 */
class PayoutLedger
{
    /** El payout entró al banco: hay que acreditarlo. */
    private const CREDITA = 'completed';

    /** El payout no llegó (o se canceló después de salir): hay que contra-asentarlo. */
    private const REVIERTE = ['failed', 'canceled'];

    /** Los dos bolsillos son `cuenta` y `cash`; el payout entra al banco. */
    private const POT = 'cuenta';

    /**
     * Pone el libro al día con el estado de este payout de Mollie.
     * Devuelve qué hizo, para que el que llama lo loguee o lo cuente.
     *
     * @param  object  $payout  el recurso Payout del SDK (o uno equivalente)
     * @return string acreditado | contra-asentado | sin_cambios | en_camino | sin_club | moneda
     */
    public function sync(object $payout): string
    {
        $payoutId = (string) ($payout->id ?? '');
        $status = (string) ($payout->status ?? '');

        if ($payoutId === '') {
            return 'sin_cambios';
        }

        $club = $this->club();

        if (! $club) {
            // Sin club no hay a quién acreditarle: un movimiento con club_id null
            // es plata sin dueño que no suma en ningún saldo. Mejor no escribir.
            Log::warning('Payout de Mollie sin club al que acreditarlo', ['payout' => $payoutId]);

            return 'sin_club';
        }

        if ($status !== self::CREDITA && ! in_array($status, self::REVIERTE, true)) {
            // requested / initiated / processing-at-bank: todavía no está en el banco.
            return 'en_camino';
        }

        $currency = strtoupper((string) ($payout->amount->currency ?? ''));

        if ($currency !== strtoupper($club->currency)) {
            // Acreditar euros como si fueran lei ensucia el saldo en silencio.
            Log::warning('Payout de Mollie en otra moneda: no se acredita', [
                'payout' => $payoutId,
                'moneda' => $currency,
                'esperada' => strtoupper($club->currency),
            ]);

            return 'moneda';
        }

        $amountCents = $this->cents($payout->amount->value ?? null);

        if ($amountCents === null) {
            Log::warning('Payout de Mollie sin monto legible: no se acredita', ['payout' => $payoutId]);

            return 'sin_cambios';
        }

        return DB::transaction(fn () => $status === self::CREDITA
            ? $this->acreditar($club, $payout, $payoutId, $amountCents, $currency)
            : $this->contraAsentar($club, $payoutId, $status));
    }

    /** Escribe el depósito en el libro: una fila por el neto y, si Mollie la informó, otra por la comisión. */
    private function acreditar(Club $club, object $payout, string $payoutId, int $amountCents, string $currency): string
    {
        $feeCents = $this->feeCents($payout, $payoutId);
        $registro = $this->registro($payoutId);

        if ($registro && $registro->club_id !== $club->id) {
            Log::warning('Payout de Mollie ya registrado en otro club: no se toca', ['payout' => $payoutId]);

            return 'sin_cambios';
        }

        $completedAt = isset($payout->completedAt) ? Carbon::parse($payout->completedAt) : null;

        if ($registro) {
            $registro->update([
                'status' => self::CREDITA,
                'amount_cents' => $amountCents,
                'fee_cents' => $feeCents,
                'completed_at' => $completedAt,
            ]);
        } else {
            $registro = MolliePayout::withoutGlobalScopes()->create([
                'club_id' => $club->id,
                'mollie_payout_id' => $payoutId,
                'status' => self::CREDITA,
                'amount_cents' => $amountCents,
                'fee_cents' => $feeCents,
                'currency' => $currency,
                'completed_at' => $completedAt,
            ]);
        }

        // Ya hay asientos vigentes de este payout: el webhook se repitió (o el
        // chequeo diario lo volvió a pasar). Se acredita una sola vez.
        if ($this->vigentes($club->id, $payoutId)->isNotEmpty()) {
            return 'sin_cambios';
        }

        $occurredOn = ($completedAt ?? now())->toDateString();

        $this->movimiento($club->id, $payoutId, 'payout', $amountCents, $occurredOn, 'Depósito de Mollie');

        if ($feeCents !== null && $feeCents !== 0) {
            // La comisión sale del mismo bolsillo, con signo negativo.
            $this->movimiento($club->id, $payoutId, 'fee', -abs($feeCents), $occurredOn, 'Comisión de Mollie');
        }

        return 'acreditado';
    }

    /**
     * El payout no llegó al banco: anula lo acreditado con un contra-asiento por
     * cada fila vigente (el depósito y, si estaba, su comisión). Las filas del
     * libro nunca se borran, igual que en CuotaLedger.
     */
    private function contraAsentar(Club $club, string $payoutId, string $status): string
    {
        $this->registro($payoutId)?->update(['status' => $status]);

        $vigentes = $this->vigentes($club->id, $payoutId);

        if ($vigentes->isEmpty()) {
            return 'sin_cambios';
        }

        foreach ($vigentes as $vigente) {
            $this->movimiento(
                $club->id,
                $payoutId,
                $vigente->kind,
                -$vigente->amount_cents,
                now()->toDateString(),
                'Payout '.$status.' en Mollie',
                $vigente->id,
            );
        }

        return 'contra-asentado';
    }

    private function movimiento(int $clubId, string $payoutId, string $kind, int $amountCents, string $occurredOn, string $note, ?int $reverses = null): void
    {
        Movement::withoutGlobalScopes()->create([
            'club_id' => $clubId,
            'pot' => self::POT,
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'occurred_on' => $occurredOn,
            'mollie_payout_id' => $payoutId,
            'reverses_movement_id' => $reverses,
            'note' => $note,
        ]);
    }

    /** La fila de este payout, si ya la escribimos. */
    private function registro(string $payoutId): ?MolliePayout
    {
        return MolliePayout::withoutGlobalScopes()
            ->where('mollie_payout_id', $payoutId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Los asientos de este payout que todavía no fueron contra-asentados (el
     * depósito y su comisión). Mismo mecanismo que CuotaLedger::vigente().
     *
     * @return Collection<int, Movement>
     */
    private function vigentes(int $clubId, string $payoutId): Collection
    {
        return Movement::withoutGlobalScopes()
            ->where('club_id', $clubId)
            ->where('mollie_payout_id', $payoutId)
            ->whereNull('reverses_movement_id')
            ->whereNotIn('id', Movement::withoutGlobalScopes()
                ->whereNotNull('reverses_movement_id')->select('reverses_movement_id'))
            ->lockForUpdate()
            ->get();
    }

    /**
     * El club dueño de la cuenta de Mollie. La credencial de Mollie es una sola
     * por instalación, así que el dueño es el único club con cuota. Si hubiera
     * más de uno no se sabe de quién es la plata: no se acredita nada.
     */
    private function club(): ?Club
    {
        $clubs = Club::withoutGlobalScopes()
            ->where('monthly_fee_cents', '>', 0)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($clubs->count() === 1) {
            return $clubs->first();
        }

        if ($clubs->count() > 1) {
            Log::warning('Hay más de un club con cuota: no se puede saber de quién es el payout de Mollie');
        }

        return null;
    }

    /**
     * La comisión que informa Mollie, o null si no la informa. NO se deriva ni
     * se estima: un número inventado corrompe el saldo en silencio, y para esa
     * diferencia ya está el ajuste contra el extracto.
     *
     * OJO: el recurso Payout del SDK no declara `deductions` (la trae la balance
     * transaction), así que hoy esto es casi siempre null. Si algún día viniera,
     * hay que confirmar antes si `amount` sigue siendo el neto depositado: si lo
     * es, la comisión NO va como movimiento porque nunca pasó por el banco.
     */
    private function feeCents(object $payout, string $payoutId): ?int
    {
        $fee = isset($payout->deductions->value) ? $this->cents($payout->deductions->value) : null;

        if ($fee === null) {
            Log::info('Payout de Mollie sin comisión legible: no se asienta ninguna', ['payout' => $payoutId]);
        }

        return $fee;
    }

    /** "288.00" -> 28800. Enteros, nunca float. */
    private function cents(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }
}
