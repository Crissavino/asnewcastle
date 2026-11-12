<?php

namespace App\Services;

use App\Models\Club;
use App\Models\MolliePayout;
use App\Models\Movement;
use App\Services\Mollie\ClubDeLaCuenta;
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

    /** Quedó registrado pero no acreditado porque vino en otra moneda. */
    public const MONEDA = 'moneda';

    /** Los dos bolsillos son `cuenta` y `cash`; el payout entra al banco. */
    private const POT = 'cuenta';

    public function __construct(private ClubDeLaCuenta $clubDeLaCuenta) {}

    /**
     * Pone el libro al día con el estado de este payout de Mollie.
     * Devuelve qué hizo, para que el que llama lo loguee o lo cuente.
     *
     * @param  object  $payout  el recurso Payout del SDK (o uno equivalente)
     * @return string acreditado | contra-asentado | sin_cambios | en_camino | moneda |
     *                sin_club | club_ambiguo | club_configurado_inexistente
     */
    public function sync(object $payout): string
    {
        $payoutId = (string) ($payout->id ?? '');
        $status = (string) ($payout->status ?? '');

        if ($payoutId === '') {
            return 'sin_cambios';
        }

        if ($status !== self::CREDITA && ! in_array($status, self::REVIERTE, true)) {
            // requested / initiated / processing-at-bank: todavía no está en el banco.
            return 'en_camino';
        }

        [$club, $motivo] = $this->clubDeLaCuenta->resolver();

        return DB::transaction(function () use ($club, $motivo, $payout, $payoutId, $status) {
            if (! $club && in_array($status, self::REVIERTE, true)) {
                // El contra-asiento NO depende del resolvedor: el dueño ya está
                // en la fila, escrito el día que sí se resolvió. Gatearlo con el
                // resolvedor dejaba acreditado en `cuenta` un depósito que el
                // banco nunca hizo, nada más que porque hoy MOLLIE_CLUB_ID quedó
                // mal o apareció un segundo club. vigentes() y movimiento() solo
                // necesitan un club_id.
                $club = Club::withoutGlobalScopes()->find($this->registro($payoutId)?->club_id);
            }

            if (! $club) {
                // Un movimiento con club_id null es plata sin dueño que no suma
                // en ningún saldo, así que no se escribe. Pero el payout existió:
                // queda registrado como SIN ACREDITAR para que se vea en la
                // pantalla y lo repesque el chequeo diario, no escondido en un
                // warning.
                return $this->registrarSinAcreditar($payout, $payoutId, $status, $motivo);
            }

            // El contra-asiento usa los importes que ya están en la base, así que
            // no depende de la moneda ni del monto que traiga ESTE payout:
            // gatearlo con esos chequeos dejaría acreditada plata que nunca llegó
            // al banco.
            return $status === self::CREDITA
                ? $this->acreditar($club, $payout, $payoutId)
                : $this->contraAsentar($club, $payoutId, $status);
        });
    }

    /** Escribe el depósito en el libro: una fila por el neto y, si corresponde, otra por la comisión. */
    private function acreditar(Club $club, object $payout, string $payoutId): string
    {
        $currency = strtoupper((string) ($payout->amount->currency ?? ''));

        if ($currency !== strtoupper($club->currency)) {
            // Acreditar euros como si fueran lei ensucia el saldo en silencio.
            Log::warning('Payout de Mollie en otra moneda: no se acredita', [
                'payout' => $payoutId,
                'moneda' => $currency,
                'esperada' => strtoupper($club->currency),
            ]);

            // No acreditarlo es correcto; no dejar rastro, no. Ese depósito
            // existe en el banco de verdad: sin fila no hay nada que mirar ni
            // nada que levante el cartel de "plata no acreditada".
            return $this->registrarMoneda($club, $payout, $payoutId, $currency);
        }

        $amountCents = $this->cents($payout->amount->value ?? null);

        if ($amountCents === null) {
            Log::warning('Payout de Mollie sin monto legible: no se acredita', ['payout' => $payoutId]);

            return 'sin_cambios';
        }

        $feeInformada = $this->feeInformada($payout);
        $feeAsentable = $this->feeAsentable($feeInformada, $payoutId);
        $registro = $this->registro($payoutId);

        // club_id null es un payout que quedó sin acreditar: ahora se adopta.
        if ($registro && $registro->club_id !== null && $registro->club_id !== $club->id) {
            Log::warning('Payout de Mollie ya registrado en otro club: no se toca', ['payout' => $payoutId]);

            return 'sin_cambios';
        }

        $completedAt = isset($payout->completedAt) ? Carbon::parse($payout->completedAt) : null;

        if ($this->anteriorALaLinea($club->id, $completedAt)) {
            Log::info('Payout anterior a la línea en la arena: ya está dentro del saldo inicial', [
                'payout' => $payoutId,
                'completado' => $completedAt?->toIso8601String(),
            ]);

            return 'anterior_a_la_linea';
        }

        $datos = [
            'club_id' => $club->id,
            'status' => self::CREDITA,
            'amount_cents' => $amountCents,
            'fee_cents' => $feeInformada,
            'currency' => $currency,
            'pending_reason' => null,
            'completed_at' => $completedAt,
        ];

        if ($registro) {
            $registro->update($datos);
        } else {
            MolliePayout::withoutGlobalScopes()->create($datos + ['mollie_payout_id' => $payoutId]);
        }

        // Ya hay asientos vigentes de este payout: el webhook se repitió (o el
        // chequeo diario lo volvió a pasar). Se acredita una sola vez.
        if ($this->vigentes($club->id, $payoutId)->isNotEmpty()) {
            return 'sin_cambios';
        }

        $occurredOn = ($completedAt ?? now())->toDateString();

        $this->movimiento($club->id, $payoutId, 'payout', $amountCents, $occurredOn, 'Depósito de Mollie');

        if ($feeAsentable !== null && $feeAsentable !== 0) {
            // La comisión sale del mismo bolsillo, con signo negativo.
            $this->movimiento($club->id, $payoutId, 'fee', -abs($feeAsentable), $occurredOn, 'Comisión de Mollie');
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

    /**
     * El payout vino en una moneda que no es la del club: se registra CON su
     * club (acá sí se sabe de quién es) y SIN movimiento. No se convierte nada:
     * la fila queda marcada sin acreditar y la levanta el mismo cartel de la
     * pantalla que los payouts sin club.
     */
    private function registrarMoneda(Club $club, object $payout, string $payoutId, string $currency): string
    {
        $registro = $this->registro($payoutId);

        if ($registro && $registro->club_id !== null && $registro->club_id !== $club->id) {
            Log::warning('Payout de Mollie ya registrado en otro club: no se toca', ['payout' => $payoutId]);

            return 'sin_cambios';
        }

        // Si ya tiene asientos vigentes, alguna vez se acreditó bien: marcarlo
        // sin acreditar sería un cartel falso y la plata seguiría en el saldo.
        if ($this->vigentes($club->id, $payoutId)->isNotEmpty()) {
            Log::warning('Payout de Mollie ya acreditado pero ahora informa otra moneda: no se toca', [
                'payout' => $payoutId,
                'moneda' => $currency,
            ]);

            return 'sin_cambios';
        }

        $datos = [
            'club_id' => $club->id,
            'status' => self::CREDITA,
            'amount_cents' => $this->cents($payout->amount->value ?? null) ?? 0,
            'currency' => $currency ?: null,
            'pending_reason' => self::MONEDA,
            'completed_at' => isset($payout->completedAt) ? Carbon::parse($payout->completedAt) : null,
        ];

        if ($registro) {
            $registro->update($datos);
        } else {
            MolliePayout::withoutGlobalScopes()->create($datos + ['mollie_payout_id' => $payoutId]);
        }

        return self::MONEDA;
    }

    /**
     * El payout existió pero no se sabe de quién es la cuenta: se registra sin
     * club y sin movimiento. Plata no acreditada tiene que estar a la vista.
     */
    private function registrarSinAcreditar(object $payout, string $payoutId, string $status, ?string $motivo): string
    {
        $motivo ??= ClubDeLaCuenta::SIN_CLUB;
        $registro = $this->registro($payoutId);

        // Si ya estaba acreditado a un club, el club se resolvió alguna vez:
        // no se le borra el dueño por una configuración que cambió hoy.
        if ($registro && $registro->club_id !== null) {
            Log::warning('Payout de Mollie ya acreditado pero ahora no se resuelve el club', [
                'payout' => $payoutId,
                'motivo' => $motivo,
            ]);

            return 'sin_cambios';
        }

        Log::warning('Payout de Mollie sin club al que acreditarlo: queda sin acreditar', [
            'payout' => $payoutId,
            'motivo' => $motivo,
        ]);

        $datos = [
            'club_id' => null,
            'status' => $status,
            'amount_cents' => $this->cents($payout->amount->value ?? null) ?? 0,
            'currency' => strtoupper((string) ($payout->amount->currency ?? '')) ?: null,
            'pending_reason' => $motivo,
            'completed_at' => isset($payout->completedAt) ? Carbon::parse($payout->completedAt) : null,
        ];

        if ($registro) {
            $registro->update($datos);
        } else {
            MolliePayout::withoutGlobalScopes()->create($datos + ['mollie_payout_id' => $payoutId]);
        }

        return $motivo;
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

    /**
     * ¿El payout ya está contado dentro del saldo inicial?
     *
     * La línea en la arena dice "a esta hora la cuenta tiene tanto", y ese
     * número sale del extracto del banco: incluye todos los payouts que ya
     * habían caído. Acreditarlos otra vez inflaría la cuenta — el chequeo
     * diario mira 30 días para atrás, así que sin esta guarda la primera
     * corrida sumaba de nuevo los depósitos del mes anterior.
     *
     * Se compara contra el `created_at` del asiento de saldo inicial (el
     * instante en que se trazó la línea), no contra su fecha, para que un
     * payout que caiga más tarde ESE MISMO DÍA sí entre.
     *
     * Sin línea trazada no hay nada que proteger. Un payout sin fecha de
     * completado se trata como nuevo: duplicar se ve en el extracto y se
     * corrige con un ajuste; perder plata no se ve.
     */
    private function anteriorALaLinea(int $clubId, ?Carbon $completedAt): bool
    {
        if ($completedAt === null) {
            return false;
        }

        $linea = Movement::withoutGlobalScopes()
            ->where('club_id', $clubId)
            ->where('kind', 'saldo_inicial')
            ->min('created_at');

        return $linea !== null && $completedAt->lessThanOrEqualTo(Carbon::parse($linea));
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
     * depósito y su comisión). "Vigente" lo define Movement::scopeVigentes(),
     * el mismo que usa CuotaLedger.
     *
     * @return Collection<int, Movement>
     */
    private function vigentes(int $clubId, string $payoutId): Collection
    {
        return Movement::withoutGlobalScopes()
            ->where('club_id', $clubId)
            ->where('mollie_payout_id', $payoutId)
            ->vigentes()
            ->lockForUpdate()
            ->get();
    }

    /**
     * La comisión que hay que asentar como movimiento aparte, o null.
     *
     * NO se deriva ni se estima: un número inventado corrompe el saldo en
     * silencio, y para esa diferencia ya está el ajuste contra el extracto.
     *
     * Y aunque Mollie la informe, por defecto NO se asienta: el SDK documenta el
     * `amount` del payout como el monto depositado "excluding any applicable
     * fees", o sea NETO. Restarle además la comisión dejaría la cuenta por
     * debajo del extracto, en silencio y para siempre. El recurso acepta campos
     * dinámicos (AllowDynamicProperties + el hydrator copia todo el JSON), así
     * que `deductions` puede aparecer solo el día que Mollie lo agregue: el flag
     * de config es lo que impide que esta rama se prenda sola. Se prende recién
     * cuando la sonda (app:mollie-saldo-diag) confirme que `amount` es BRUTO.
     */
    private function feeAsentable(?int $feeInformada, string $payoutId): ?int
    {
        if ($feeInformada === null) {
            Log::info('Payout de Mollie sin comisión legible: no se asienta ninguna', ['payout' => $payoutId]);

            return null;
        }

        if (! config('services.mollie.payout_fee_aparte')) {
            Log::info('Payout de Mollie con comisión informada, pero el monto se toma como neto: no se asienta', [
                'payout' => $payoutId,
                'comision_cents' => $feeInformada,
            ]);

            return null;
        }

        return $feeInformada;
    }

    /** La comisión tal como la informa Mollie, solo para dejarla registrada. */
    private function feeInformada(object $payout): ?int
    {
        return isset($payout->deductions->value) ? $this->cents($payout->deductions->value) : null;
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
