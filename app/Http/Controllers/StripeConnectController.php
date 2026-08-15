<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\Stripe\StripeGateway;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StripeConnectController extends Controller
{
    /** El manager arranca (o retoma) el onboarding de Stripe Connect Express. */
    public function start(StripeGateway $stripe): Response
    {
        Gate::authorize('create', Event::class);

        $club = app(CurrentClub::class)->club();

        if (! $club->stripe_account_id) {
            $club->update(['stripe_account_id' => $stripe->createExpressAccount($club)]);
        }

        $url = $stripe->createOnboardingLink(
            $club,
            route('stripe.retorno'),
            route('stripe.onboarding'),
        );

        return Inertia::location($url);
    }

    /** Vuelta del flujo de Stripe: si la cuenta quedó habilitada, se marca. */
    public function back(StripeGateway $stripe): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $club = app(CurrentClub::class)->club();

        if ($club->stripe_onboarded_at === null && $stripe->chargesEnabled($club)) {
            $club->update(['stripe_onboarded_at' => now()]);
        }

        return redirect()->route('cuota');
    }
}
