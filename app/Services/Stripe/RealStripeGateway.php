<?php

namespace App\Services\Stripe;

use App\Models\Club;
use App\Models\Due;
use Stripe\StripeClient;

class RealStripeGateway implements StripeGateway
{
    public function __construct(protected StripeClient $client)
    {
    }

    public function createExpressAccount(Club $club): string
    {
        $account = $this->quiet(fn () => $this->client->accounts->create([
            'type' => 'express',
            'country' => 'RO',
            'business_type' => 'non_profit',
            'metadata' => ['club_id' => $club->id],
        ]));

        return $account->id;
    }

    public function createOnboardingLink(Club $club, string $returnUrl, string $refreshUrl): string
    {
        $link = $this->quiet(fn () => $this->client->accountLinks->create([
            'account' => $club->stripe_account_id,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding',
        ]));

        return $link->url;
    }

    public function chargesEnabled(Club $club): bool
    {
        if (! $club->stripe_account_id) {
            return false;
        }

        return (bool) $this->quiet(fn () => $this->client->accounts->retrieve($club->stripe_account_id))->charges_enabled;
    }

    /**
     * stripe-php convierte el header "stripe-notice" (recomendaciones tipo
     * "migrá a Accounts v2") en un E_USER_WARNING, y Laravel lo eleva a
     * excepción. Acá lo bajamos a log: es un aviso, no un error.
     */
    protected function quiet(callable $fn): mixed
    {
        set_error_handler(function (int $severity, string $message, string $file = '') {
            if ($severity === E_USER_WARNING && str_contains($file, 'stripe-php')) {
                logger()->info('Stripe notice: '.$message);

                return true;
            }

            return false;
        });

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function createCheckoutUrl(Due $due, string $successUrl, string $cancelUrl): string
    {
        $club = $due->club;
        $fee = (int) round($due->amount_cents * config('services.stripe.application_fee_bps') / 10000);

        // Cargo directo en la cuenta conectada: los fondos van al club,
        // la plataforma solo cobra la comisión.
        $session = $this->quiet(fn () => $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($club->currency),
                    'unit_amount' => $due->amount_cents,
                    'product_data' => [
                        'name' => $club->name.' · '.$due->period->format('Y-m'),
                    ],
                ],
            ]],
            'payment_intent_data' => array_filter([
                'application_fee_amount' => $fee ?: null,
                'metadata' => ['due_id' => $due->id],
            ]),
            'metadata' => ['due_id' => $due->id],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], ['stripe_account' => $club->stripe_account_id]));

        return $session->url;
    }
}
