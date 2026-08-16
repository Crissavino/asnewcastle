<?php

namespace App\Http\Controllers;

use App\Models\Due;
use App\Models\Event;
use App\Models\Expense;
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
            // Quién está al día y quién debe: lo ve todo el plantel
            'plantel' => $this->squadStatus($period),
            // Saldo y movimientos del mes: transparencia para todos
            'resumen' => $this->cashSummary($period),
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

    /** Estado de cuota por jugador, visible para todo el plantel. */
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
                'due_status' => $dues->get($m->id)?->status,
            ])
            ->values()
            ->all();
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
