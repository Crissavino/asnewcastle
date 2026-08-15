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
        $account = $this->client->accounts->create([
            'type' => 'express',
            'country' => 'RO',
            'business_type' => 'non_profit',
            'metadata' => ['club_id' => $club->id],
        ]);

        return $account->id;
    }

    public function createOnboardingLink(Club $club, string $returnUrl, string $refreshUrl): string
    {
        $link = $this->client->accountLinks->create([
            'account' => $club->stripe_account_id,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding',
        ]);

        return $link->url;
    }

    public function chargesEnabled(Club $club): bool
    {
        if (! $club->stripe_account_id) {
            return false;
        }

        return (bool) $this->client->accounts->retrieve($club->stripe_account_id)->charges_enabled;
    }

    public function createCheckoutUrl(Due $due, string $successUrl, string $cancelUrl): string
    {
        $club = $due->club;
        $fee = (int) round($due->amount_cents * config('services.stripe.application_fee_bps') / 10000);

        // Cargo directo en la cuenta conectada: los fondos van al club,
        // la plataforma solo cobra la comisión.
        $session = $this->client->checkout->sessions->create([
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
        ], ['stripe_account' => $club->stripe_account_id]);

        return $session->url;
    }
}
