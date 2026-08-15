<?php

namespace Tests\Support;

use App\Models\Club;
use App\Models\Due;
use App\Services\Stripe\StripeGateway;

class FakeStripeGateway implements StripeGateway
{
    public array $checkoutsFor = [];

    public bool $chargesEnabled = true;

    public function createExpressAccount(Club $club): string
    {
        return 'acct_test_'.$club->id;
    }

    public function createOnboardingLink(Club $club, string $returnUrl, string $refreshUrl): string
    {
        return 'https://connect.stripe.test/onboarding/'.$club->stripe_account_id;
    }

    public function chargesEnabled(Club $club): bool
    {
        return $this->chargesEnabled;
    }

    public function createCheckoutUrl(Due $due, string $successUrl, string $cancelUrl): string
    {
        $this->checkoutsFor[] = $due->id;

        return 'https://checkout.stripe.test/pay/due-'.$due->id;
    }
}
