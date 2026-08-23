<?php

namespace App\Http\Controllers;

use App\Models\Due;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Member;
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
        // Rol EFECTIVO: si el dueño está "viendo como jugador", acá figura player.
        $isManager = $current->actsAsManager();
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
            // Quién está al día y quién debe: lo ve todo el plantel
            'plantel' => $this->squadStatus($period),
            // Saldo y movimientos del mes: transparencia para todos
            'resumen' => $this->cashSummary($period),
            // Débito automático del jugador: estado + monto con descuento
            'subscription' => [
                'status' => $member->subscription_status,
                'discount_cents' => $club->subscription_discount_cents,
                'subscribed_fee_cents' => $member->subscribedFeeCents(),
            ],
        ];

        // La caja (recaudación del mes, histórico y deudores) la ve TODO el
        // plantel — transparencia. Las ACCIONES (marcar pago, becar, reclamar)
        // siguen siendo solo del manager: se gatean en el front por el rol.
        $dues = Due::query()->whereDate('period', $period)->with('member.user:id,name')->get();
        $active = $dues->where('status', '!=', 'waived'); // condonadas fuera del objetivo

        $props['caja'] = [
            'collected_cents' => $active->where('status', 'paid')->sum('amount_cents'),
            'target_cents' => $active->sum('amount_cents'),
            'paid_count' => $active->where('status', 'paid')->count(),
            'total_count' => $active->count(),
            // Histórico de cuotas (todos los meses): lo cobrado vs lo que se debe
            'paid_all_cents' => (int) Due::query()->where('status', 'paid')->sum('amount_cents'),
            'owed_all_cents' => (int) Due::query()->where('status', 'pending')->sum('amount_cents'),
            'debtors' => $dues->where('status', 'pending')->values()->map(fn ($d) => [
                'due_id' => $d->id,
                'name' => $d->member->user->name,
                'shirt_number' => $d->member->shirt_number,
                'amount_cents' => $d->amount_cents,
            ]),
        ];

        // Solo el delegado: configuración de cuota y gestión de gastos.
        if ($isManager) {
            // El detalle de gastos del mes, con opción de borrar
            $props['gastos'] = Expense::query()
                ->whereBetween('spent_on', [$period, $period->copy()->endOfMonth()])
                ->orderByDesc('spent_on')
                ->with('event:id,opponent,kind')
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'category' => $e->category,
                    'description' => $e->description,
                    'amount_cents' => $e->amount_cents,
                    'spent_on' => $e->spent_on->toDateString(),
                    'event' => $e->event?->opponent,
                ]);

            $props['categorias'] = Expense::CATEGORIES;

            // Configuración de cuota: monto del club y tipo por jugador.
            // SOLO manager: el tipo de cuota de cada uno no lo ve nadie más.
            $props['config'] = [
                'monthly_fee_cents' => $club->monthly_fee_cents,
                'subscription_discount_cents' => $club->subscription_discount_cents,
                'members' => $club->activeMembers()
                    ->with('user:id,name')
                    ->orderBy('shirt_number')
                    ->get()
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->user->name,
                        'shirt_number' => $m->shirt_number,
                        'fee_type' => $m->fee_type,
                        'custom_fee_cents' => $m->custom_fee_cents,
                        'subscription_status' => $m->subscription_status,
                    ]),
            ];

            // Eventos recientes para atar un gasto (el árbitro del partido vs X)
            $props['eventos'] = Event::query()
                ->orderByDesc('starts_at')
                ->limit(15)
                ->get(['id', 'opponent', 'kind', 'starts_at'])
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'opponent' => $e->opponent,
                    'date' => $e->starts_at->format('d/m'),
                ]);
        }

        return Inertia::render('Cuota', $props);
    }

    /**
     * Estado de cuota por jugador, visible para todo el plantel.
     * Hacia afuera solo existe "al día" o "debe": becados y condonados
     * figuran al día — el tipo de cuota de cada uno es privado.
     */
    protected function squadStatus($period): array
    {
        $dues = Due::query()->whereDate('period', $period)->get()->keyBy('member_id');

        return app(CurrentClub::class)->club()->activeMembers()
            ->with('user:id,name')
            ->orderBy('shirt_number')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'shirt_number' => $m->shirt_number,
                'due_status' => $dues->get($m->id)?->status === 'pending' ? 'pending' : 'paid',
            ])
            ->values()
            ->all();
    }

    /** El delegado edita la cuota mensual del club. */
    public function updateConfig(Request $request): BaseResponse
    {
        Gate::authorize('create', Event::class);

        $validated = $request->validate([
            'monthly_fee_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'subscription_discount_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $club = app(CurrentClub::class)->club();
        $club->update([
            'monthly_fee_cents' => $validated['monthly_fee_cents'],
            'subscription_discount_cents' => $validated['subscription_discount_cents'] ?? $club->subscription_discount_cents,
        ]);

        // Las pendientes sin pagar del mes (de cuota normal) toman el monto nuevo
        Due::query()
            ->whereDate('period', now()->startOfMonth())
            ->where('status', 'pending')
            ->whereDoesntHave('payments')
            ->whereHas('member', fn ($q) => $q->where('fee_type', 'normal'))
            ->update(['amount_cents' => $validated['monthly_fee_cents']]);

        return back();
    }

    /** Tipo de cuota por jugador: normal, becado o personalizada. Privado del manager. */
    public function setMemberFee(Request $request, Member $member): BaseResponse
    {
        Gate::authorize('create', Event::class);
        $current = app(CurrentClub::class);
        abort_unless($member->club_id === $current->id(), 404);

        $validated = $request->validate([
            'fee_type' => ['required', Rule::in(Member::FEE_TYPES)],
            'custom_fee_cents' => ['required_if:fee_type,custom', 'nullable', 'integer', 'min:1', 'max:10000000'],
        ]);

        $member->update([
            'fee_type' => $validated['fee_type'],
            'custom_fee_cents' => $validated['fee_type'] === 'custom' ? $validated['custom_fee_cents'] : null,
        ]);

        // La cuota pendiente del mes se ajusta al tipo nuevo (si no tiene pagos)
        $due = Due::query()
            ->where('member_id', $member->id)
            ->whereDate('period', now()->startOfMonth())
            ->where('status', 'pending')
            ->whereDoesntHave('payments')
            ->first();

        if ($due) {
            $amount = $member->fresh()->monthlyFeeCents();

            if ($amount === null || $amount === 0) {
                $due->delete();
            } else {
                $due->update(['amount_cents' => $amount]);
            }
        }

        return back();
    }

    /** Saldo acumulado y movimientos del mes. Caja lógica, no el banco. */
    protected function cashSummary($period): array
    {
        $incomeAll = (int) Due::query()->where('status', 'paid')->sum('amount_cents');
        $spentAll = (int) Expense::query()->sum('amount_cents');

        $monthExpenses = Expense::query()
            ->whereBetween('spent_on', [$period, $period->copy()->endOfMonth()])
            ->get();

        return [
            'balance_cents' => $incomeAll - $spentAll,
            'month_in_cents' => (int) Due::query()->whereDate('period', $period)->where('status', 'paid')->sum('amount_cents'),
            'month_out_cents' => (int) $monthExpenses->sum('amount_cents'),
            'by_category' => $monthExpenses->groupBy('category')
                ->map(fn ($g) => (int) $g->sum('amount_cents'))
                ->sortDesc()
                ->all(),
        ];
    }

    public function pay(Request $request, Due $due, StripeGateway $stripe): BaseResponse
    {
        $current = app(CurrentClub::class);
        $current->assertOwns($due);

        abort_unless($due->member_id === $current->member()->id, 403);
        abort_unless($due->isPending(), 400);
        abort_unless($current->club()->stripe_onboarded_at !== null, 400);

        $native = $request->boolean('native');

        $url = $stripe->createCheckoutUrl(
            $due,
            $this->returnUrl('pago', 'ok', $native),
            $this->returnUrl('pago', 'cancelado', $native),
        );

        return response()->json(['url' => $url]);
    }

    /**
     * URL de retorno del checkout. En la app nativa pasa por la página puente
     * (/pago/volver) que reabre la app por deep link; en la web va directo.
     */
    protected function returnUrl(string $key, string $value, bool $native): string
    {
        if ($native) {
            return route('pago.volver', ['to' => 'cuota', $key => $value]);
        }

        return route('cuota').'?'.$key.'='.$value;
    }

    /**
     * El jugador activa el débito automático mensual. Se le cobra la cuota
     * con descuento todos los meses, sobre la cuenta conectada del club.
     */
    public function subscribe(Request $request, StripeGateway $stripe): BaseResponse
    {
        $current = app(CurrentClub::class);
        $member = $current->member();

        abort_unless($current->club()->stripe_onboarded_at !== null, 400);
        // Becado o sin monto: no hay suscripción posible
        abort_if(($member->subscribedFeeCents() ?? 0) <= 0, 400);
        // Ya suscripto: no duplicar
        abort_if($member->isSubscribed(), 400);

        $native = $request->boolean('native');

        $url = $stripe->createSubscriptionCheckoutUrl(
            $member,
            $this->returnUrl('suscripcion', 'ok', $native),
            $this->returnUrl('suscripcion', 'cancelado', $native),
        );

        return response()->json(['url' => $url]);
    }

    /** Solo el delegado corta el débito automático de un jugador. */
    public function cancelSubscription(Member $member, StripeGateway $stripe): BaseResponse
    {
        Gate::authorize('create', Event::class);
        abort_unless($member->club_id === app(CurrentClub::class)->id(), 404);

        $stripe->cancelSubscription($member);
        $member->update(['subscription_status' => 'canceled', 'stripe_subscription_id' => null]);

        return back();
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
