<?php

namespace App\Services\Mollie;

use App\Models\Due;
use App\Models\Member;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

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
     * cobro automático arranca el 1° del mes que viene (el mes actual ya lo cubrió
     * el primer pago). Idempotente: si ya hay subscription, no duplica.
     */
    public function startSubscription(Member $member, string $webhookUrl): void
    {
        if ($member->mollie_subscription_id || ! $member->mollie_customer_id) {
            return;
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
                'startDate' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
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

    /** Trae un pago por id (para verificar el estado desde el webhook). */
    public function getPayment(string $paymentId): Payment
    {
        return $this->mollie->payments->get($paymentId, [], $this->testmode());
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
