<?php

namespace App\Http\Controllers;

use App\Models\Due;
use App\Models\Event;
use App\Services\Stripe\StripeGateway;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\CurrentClub;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class CuotaController extends Controller
{
    public function show(): Response
    {
        $current = app(CurrentClub::class);
        $club = $current->club();
        $member = $current->member();
        $isManager = $member->isManager();
        $period = now()->startOfMonth();

        $myDue = Due::query()
            ->where('member_id', $member->id)
            ->whereDate('period', $period)
            ->first();

        $props = [
            'currency' => $club->currency,
            'stripe_ready' => $club->stripe_onboarded_at !== null,
            'my_due' => $myDue ? [
                'id' => $myDue->id,
                'amount_cents' => $myDue->amount_cents,
                'status' => $myDue->status,
                'due_date' => $myDue->due_date->toDateString(),
                'period' => $myDue->period->toDateString(),
            ] : null,
        ];

        // La caja es solo del delegado: un player no la ve.
        if ($isManager) {
            $dues = Due::query()->whereDate('period', $period)->with('member.user:id,name')->get();

            // Las condonadas no cuentan ni como deuda ni como objetivo
            $active = $dues->where('status', '!=', 'waived');

            $props['caja'] = [
                'collected_cents' => $active->where('status', 'paid')->sum('amount_cents'),
                'target_cents' => $active->sum('amount_cents'),
                'paid_count' => $active->where('status', 'paid')->count(),
                'total_count' => $active->count(),
                'debtors' => $dues->where('status', 'pending')->values()->map(fn ($d) => [
                    'due_id' => $d->id,
                    'name' => $d->member->user->name,
                    'shirt_number' => $d->member->shirt_number,
                    'amount_cents' => $d->amount_cents,
                ]),
            ];
        }

        return Inertia::render('Cuota', $props);
    }

    public function pay(Due $due, StripeGateway $stripe): BaseResponse
    {
        $current = app(CurrentClub::class);
        $current->assertOwns($due);

        abort_unless($due->member_id === $current->member()->id, 403);
        abort_unless($due->isPending(), 400);
        abort_unless($current->club()->stripe_onboarded_at !== null, 400);

        $url = $stripe->createCheckoutUrl(
            $due,
            route('cuota').'?pago=ok',
            route('cuota').'?pago=cancelado',
        );

        return Inertia::location($url);
    }

    /**
     * El delegado marca la cuota a mano: pagó en efectivo, se condona,
     * o vuelve a pendiente. Las pagadas por Stripe no se tocan.
     */
    public function setStatus(Request $request, Due $due): BaseResponse
    {
        Gate::authorize('create', Event::class);
        app(CurrentClub::class)->assertOwns($due);

        abort_if($due->payments()->exists(), 400, 'Pagada por Stripe: no se cambia a mano.');

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'paid', 'waived'])],
        ]);

        $due->update(['status' => $validated['status']]);

        return back();
    }

    public function claim(WhatsAppChannel $whatsapp): BaseResponse
    {
        Gate::authorize('create', Event::class);

        $current = app(CurrentClub::class);
        $club = $current->club();

        $pending = Due::query()
            ->where('status', 'pending')
            ->whereDate('period', now()->startOfMonth())
            ->with('member.user')
            ->get();

        foreach ($pending as $due) {
            $user = $due->member->user;
            $locale = $user->locale ?? config('app.fallback_locale');

            $whatsapp->sendTemplate($user->phone, config('services.twilio.dues_template_sid') ?? 'dues', [
                '1' => strtok($user->name ?? '', ' ') ?: (string) $user->name,
                '2' => number_format($due->amount_cents / 100, 2).' '.$club->currency,
                '3' => $due->period->locale($locale)->translatedFormat('F Y'),
            ]);
        }

        return back()->with('status', $pending->count());
    }
}
