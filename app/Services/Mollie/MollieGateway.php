<?php

namespace App\Services\Mollie;

use App\Models\Due;
use App\Models\Member;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Balance;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Payout;

/**
 * Cobro de cuotas con Mollie sobre la cuenta PROPIA del club (no Connect).
 * Recurrente = Customer + primer pago (crea el mandato) + Subscription mensual,
 * que Mollie cobra automático cada mes. La plata va directo a la cuenta del club.
 *
 * SDK v3: `testmode` es un argumento booleano aparte. Con access token
 * (access_...) las llamadas con contexto de perfil (pagos, suscripciones)
 * necesitan `profileId` en el payload; los customers son a nivel organización.
 */
class MollieGateway
{
    /** Marca en caché de que el saldo no se pudo leer: no es un monto, es "sin dato". */
    private const TRANSITO_SIN_DATO = 'sin_dato';

    public function __construct(protected MollieApiClient $mollie) {}

    public function ready(): bool
    {
        return config('services.mollie.enabled') && filled(config('services.mollie.key'));
    }

    /** Pago puntual de una cuota pendiente (checkout con tarjeta). Devuelve la URL. */
    public function createDueCheckoutUrl(Due $due, string $redirectUrl, string $webhookUrl): string
    {
        $customerId = $this->ensureCustomer($due->member);

        $payment = $this->mollie->payments->create($this->withProfile([
            'amount' => $this->money($due->amount_cents, $due->club->currency),
            'description' => $due->club->name.' · '.$due->period->format('Y-m'),
            'redirectUrl' => $redirectUrl,
            'webhookUrl' => $webhookUrl,
            'customerId' => $customerId,
            'sequenceType' => 'oneoff',
            'metadata' => ['due_id' => (string) $due->id],
        ]), [], $this->testmode());

        return $payment->getCheckoutUrl();
    }

    /**
     * Alta del débito automático: primer pago (sequenceType first) que pide la
     * tarjeta una vez y crea el mandato. La suscripción mensual se crea recién
     * cuando el webhook confirma este pago como `paid`. Este primer pago YA
     * cubre el mes en curso.
     */
    public function createSubscriptionCheckoutUrl(Member $member, string $redirectUrl, string $webhookUrl): string
    {
        $customerId = $this->ensureCustomer($member);

        $payment = $this->mollie->payments->create($this->withProfile([
            'amount' => $this->money((int) $member->subscribedFeeCents(), $member->club->currency),
            'description' => $member->club->name.' · Cuota mensual (alta)',
            'redirectUrl' => $redirectUrl,
            'webhookUrl' => $webhookUrl,
            'customerId' => $customerId,
            'sequenceType' => 'first',
            // Sin forzar método: para un primer pago Mollie muestra solo los que
            // soportan mandato (tarjeta, Apple Pay, Google Pay). No hardcodear.
            'metadata' => ['member_id' => (string) $member->id, 'purpose' => 'subscription_first'],
        ]), [], $this->testmode());

        return $payment->getCheckoutUrl();
    }

    /**
     * Crea la suscripción mensual una vez que el mandato quedó válido. El primer
     * cobro automático arranca en $startDate o, por defecto, el 1° del mes que
     * viene (el mes que cubrió el primer pago no se vuelve a cobrar).
     * Idempotente: si ya hay subscription, no duplica.
     */
    public function startSubscription(Member $member, string $webhookUrl, ?string $startDate = null): void
    {
        if (! $member->mollie_customer_id) {
            return;
        }

        // Ya tiene suscripción: si sigue viva, no duplicamos (es la guarda que
        // hace idempotente al reintento del webhook). Pero si Mollie la canceló
        // —se le agotaron los 5 reintentos—, el id guardado es un fantasma: sin
        // esto el jugador pagaba el alta de nuevo y el débito no se reactivaba
        // nunca, porque acá cortábamos antes de crear la nueva.
        if ($member->mollie_subscription_id) {
            if ($this->subscriptionIsAlive($member)) {
                return;
            }

            $member->update(['mollie_subscription_id' => null]);
            $member->refresh();
        }

        $amount = (int) $member->subscribedFeeCents();

        if ($amount <= 0) {
            return;
        }

        $subscription = $this->mollie->subscriptions->createForId(
            $member->mollie_customer_id,
            $this->withProfile([
                'amount' => $this->money($amount, $member->club->currency),
                'interval' => '1 month',
                'startDate' => $startDate ?? now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'description' => $member->club->name.' · Cuota mensual · #'.$member->id,
                'webhookUrl' => $webhookUrl,
                'metadata' => ['member_id' => (string) $member->id],
            ]),
            $this->testmode(),
        );

        $member->update([
            'mollie_subscription_id' => $subscription->id,
            'subscription_status' => 'active',
        ]);
    }

    /**
     * Reprograma el débito: cancela la suscripción vigente (si hay) y crea una
     * nueva con el primer cobro en $startDate. Reusa el mandato del customer.
     * Se usa cuando el primer pago ya cubrió un mes distinto al que asumía el
     * alta (ej: el alta cuenta como septiembre, así que el cobro arranca en
     * octubre en vez del mes que viene).
     */
    public function resubscribe(Member $member, string $webhookUrl, string $startDate): void
    {
        if (! $member->mollie_customer_id) {
            return;
        }

        if ($member->mollie_subscription_id) {
            try {
                $this->cancelSubscriptionById($member->mollie_customer_id, $member->mollie_subscription_id);
            } catch (\Throwable) {
                // Ya cancelada o inexistente: seguimos y creamos la nueva.
            }
        }

        $amount = (int) $member->subscribedFeeCents();

        if ($amount <= 0) {
            return;
        }

        $subscription = $this->mollie->subscriptions->createForId(
            $member->mollie_customer_id,
            $this->withProfile([
                'amount' => $this->money($amount, $member->club->currency),
                'interval' => '1 month',
                'startDate' => $startDate,
                'description' => $member->club->name.' · Cuota mensual · #'.$member->id,
                'webhookUrl' => $webhookUrl,
                'metadata' => ['member_id' => (string) $member->id],
            ]),
            $this->testmode(),
        );

        $member->update([
            'mollie_subscription_id' => $subscription->id,
            'subscription_status' => 'active',
        ]);
    }

    /** Cancela el débito automático del jugador. */
    public function cancelSubscription(Member $member): void
    {
        if (! $member->mollie_customer_id || ! $member->mollie_subscription_id) {
            return;
        }

        $this->cancelSubscriptionById($member->mollie_customer_id, $member->mollie_subscription_id);
    }

    /** Cancela una suscripción por ids (para el job en segundo plano). */
    public function cancelSubscriptionById(string $customerId, string $subscriptionId): void
    {
        $this->mollie->subscriptions->cancelForId($customerId, $subscriptionId, $this->testmode());
    }

    /**
     * ¿La suscripción guardada sigue cobrando? `active` y `pending` sí; una
     * `canceled`, `suspended` o `completed` —o una que ya no existe— no.
     * Ante un error de red devolvemos true: preferimos no crear una
     * suscripción de más (cobrarle dos veces) por una caída pasajera.
     */
    protected function subscriptionIsAlive(Member $member): bool
    {
        try {
            $subscription = $this->mollie->subscriptions->getForId(
                $member->mollie_customer_id,
                $member->mollie_subscription_id,
                $this->testmode(),
            );
        } catch (ApiException) {
            // 404: no existe en este entorno / fue borrada. Se puede recrear.
            return false;
        } catch (\Throwable) {
            return true;
        }

        return in_array($subscription->status, ['active', 'pending'], true);
    }

    /** Trae un pago por id (para verificar el estado desde el webhook). */
    public function getPayment(string $paymentId): Payment
    {
        return $this->mollie->payments->get($paymentId, [], $this->testmode());
    }

    /**
     * Trae un payout por id: el depósito de Mollie en el banco del club. Es la
     * verdad del webhook de payouts (el body solo manda el id).
     */
    public function getPayout(string $payoutId): Payout
    {
        return $this->mollie->payouts->get($payoutId, $this->testmode());
    }

    /**
     * Los payouts creados desde $desde, el más nuevo primero. La API de payouts
     * no filtra por fecha: se recorre todo y se descartan los anteriores a $desde.
     *
     * @return array<int, Payout>
     */
    public function listPayouts(CarbonInterface $desde): array
    {
        $payouts = [];

        foreach ($this->mollie->payouts->iterator(null, 50, ['testmode' => $this->testmode()]) as $payout) {
            if (filled($payout->createdAt ?? null) && Carbon::parse($payout->createdAt)->lt($desde)) {
                continue; // no se asume el orden de la API: se sigue recorriendo
            }

            $payouts[] = $payout;
        }

        return $payouts;
    }

    /** El saldo principal de la cuenta de Mollie (balances.read). */
    public function getBalance(): Balance
    {
        return $this->mollie->balances->primary($this->testmode());
    }

    /**
     * La plata que ya es del club pero todavía no llegó a su banco: lo que
     * Mollie tiene disponible, lo que viene en camino y lo que ya está en un payout encolado. NO se deriva del
     * libro: nuestros pagos son brutos y el payout es neto, así que una resta
     * propia se inflaría con cada comisión. Se lee del saldo real.
     *
     * Devuelve null —NUNCA 0— si no se puede leer: un cero falso se lee como
     * "no hay plata en camino" y el dueño decide si gasta mirando este número.
     *
     * Esto se lee sincrónico en cada carga de /cuota, de cada jugador, así que
     * el fallo TAMBIÉN se cachea: sin eso, con Mollie caída o lenta, cada
     * request de cada miembro le vuelve a pegar y espera el timeout del SDK, y
     * la pestaña Cuota queda inusable para todo el plantel. Se cachea 60
     * segundos y no los 5 minutos del éxito: lo cacheado es `null` —la pantalla
     * sigue mostrando el guion, nunca un cero— y un minuto es corto como para
     * que un error transitorio no deje la pantalla sin tránsito un rato largo,
     * pero suficiente para que una caída no se multiplique por jugador.
     */
    public function transitCents(): ?int
    {
        $key = 'mollie.transito';
        $cacheado = Cache::get($key);

        if (is_int($cacheado)) {
            return $cacheado;
        }

        if ($cacheado === self::TRANSITO_SIN_DATO) {
            return null;
        }

        $cents = $this->leerTransitCents();

        Cache::put(
            $key,
            $cents ?? self::TRANSITO_SIN_DATO,
            $cents === null ? now()->addSeconds(60) : now()->addMinutes(5),
        );

        return $cents;
    }

    /** Un monto de Mollie a centavos. Ausente o ilegible es cero, no "sin dato". */
    private function cents(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value * 100) : 0;
    }

    private function leerTransitCents(): ?int
    {
        try {
            $balance = $this->getBalance();

            // CONFIRMADO con la cuenta real (app:mollie-saldo-diag, 12.11.2026):
            // el saldo trae availableAmount como {currency, value:"293.30"}, pero
            // **incomingAmount y outgoingAmount vienen AUSENTES cuando no hay nada
            // en ese estado** — no como 0. Por eso solo availableAmount es
            // obligatorio: si falta, no sabemos nada y devolvemos null. Un entrante
            // o saliente ausente SÍ es cero, y tratarlo como "sin dato" dejaría el
            // tránsito en guion para siempre.
            // Los tres son disjuntos: la sonda mostró que el payout del 11.11 salió
            // de available con un asiento outgoing-transfer, así que sumarlos no
            // cuenta dos veces.
            $moneda = strtoupper((string) ($balance->currency ?? ''));
            $disponible = $balance->availableAmount->value ?? null;

            if ($moneda !== 'RON' || ! is_numeric($disponible)) {
                Log::warning('Saldo de Mollie con forma inesperada: el tránsito queda sin dato', [
                    'moneda' => $moneda,
                    'disponible' => $balance->availableAmount ?? null,
                ]);

                return null;
            }

            return $this->cents($disponible)
                + $this->cents($balance->incomingAmount->value ?? null)
                + $this->cents($balance->outgoingAmount->value ?? null);
        } catch (\Throwable $e) {
            Log::warning('No se pudo leer el saldo de Mollie: el tránsito queda sin dato', [
                'codigo' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Devuelve el customer Mollie del jugador, creándolo si hace falta. Se
     * auto-repara: si el id guardado no existe en el entorno actual (típico al
     * pasar de test a live, donde los customers de test no existen), crea uno
     * nuevo. Así el cambio test→live no rompe el checkout.
     */
    protected function ensureCustomer(Member $member): string
    {
        if ($member->mollie_customer_id) {
            try {
                $this->mollie->customers->get($member->mollie_customer_id, $this->testmode());

                return $member->mollie_customer_id;
            } catch (\Throwable) {
                // El customer no existe en este entorno: seguimos y creamos uno.
            }
        }

        $customer = $this->mollie->customers->create([
            'name' => $member->user->name ?: ('Jugador #'.$member->id),
            'metadata' => ['member_id' => (string) $member->id],
        ], $this->testmode());

        // Nuevo customer: la subscription vieja (de otro entorno) ya no aplica.
        $member->update([
            'mollie_customer_id' => $customer->id,
            'mollie_subscription_id' => null,
        ]);

        return $customer->id;
    }

    /** Monto en formato Mollie: {currency, value} con 2 decimales. */
    protected function money(int $cents, string $currency): array
    {
        return [
            'currency' => strtoupper($currency),
            'value' => number_format($cents / 100, 2, '.', ''),
        ];
    }

    /** Agrega profileId al payload cuando la auth es access token. */
    protected function withProfile(array $payload): array
    {
        if ($this->usesAccessToken()) {
            $payload['profileId'] = config('services.mollie.profile_id');
        }

        return $payload;
    }

    protected function testmode(): bool
    {
        return $this->usesAccessToken() && (bool) config('services.mollie.testmode');
    }

    protected function usesAccessToken(): bool
    {
        return str_starts_with((string) config('services.mollie.key'), 'access_');
    }
}
