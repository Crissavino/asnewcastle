<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\Stripe\StripeGateway;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;

class StripeConnectController extends Controller
{
    /** El manager arranca (o retoma) el onboarding de Stripe Connect Express. */
    public function start(StripeGateway $stripe): Response|RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $club = app(CurrentClub::class)->club();

        try {
            if (! $club->stripe_account_id) {
                $club->update(['stripe_account_id' => $stripe->createExpressAccount($club)]);
            }

            $url = $stripe->createOnboardingLink(
                $club,
                route('stripe.retorno'),
                route('stripe.onboarding'),
            );
        } catch (ApiErrorException $e) {
            // Error de configuración de Stripe (ej: Connect sin habilitar):
            // se muestra en la pantalla en vez de reventar con un 500
            report($e);

            return back()->withErrors(['stripe' => $e->getMessage()]);
        }

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
