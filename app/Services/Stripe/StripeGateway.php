<?php

namespace App\Services\Stripe;

use App\Models\Club;
use App\Models\Due;
use App\Models\Member;

/**
 * Regla de oro: la plata NUNCA pasa por una cuenta de la plataforma.
 * Todo cargo es directo sobre la cuenta conectada del club, con
 * application_fee_amount como comisión.
 */
interface StripeGateway
{
    /** Crea la cuenta Express del club y devuelve el acct_... */
    public function createExpressAccount(Club $club): string;

    public function createOnboardingLink(Club $club, string $returnUrl, string $refreshUrl): string;

    public function chargesEnabled(Club $club): bool;

    /** Checkout hosteado por Stripe sobre la cuenta conectada. Devuelve la URL. */
    public function createCheckoutUrl(Due $due, string $successUrl, string $cancelUrl): string;

    /**
     * Checkout de suscripción (débito automático mensual) del jugador sobre la
     * cuenta conectada, con el monto ya descontado. Devuelve la URL.
     */
    public function createSubscriptionCheckoutUrl(Member $member, string $successUrl, string $cancelUrl): string;

    /** Cancela el débito automático del jugador (subscription en la cuenta conectada). */
    public function cancelSubscription(Member $member): void;
}
