<?php

namespace App\Http\Controllers;

use App\Jobs\SendEventConvocation;
use App\Models\Due;
use App\Models\Event;
use App\Models\Registration;
use App\Services\PredictionService;
use App\Services\SystemMessages;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AgendaController extends Controller
{
    public function index(): Response
    {
        $current = app(CurrentClub::class);
        $member = $current->member();
        // Rol EFECTIVO: si el dueño está "viendo como jugador", figura player.
        $isManager = $current->actsAsManager();

        // El plantel activo, una sola vez: para el conteo y para saber quién
        // NO contestó (los que no tienen fila de asistencia en el evento).
        $roster = $current->club()->activeMembers()
            ->orderBy('shirt_number')
            ->with('user:id,name')
            ->get();
        $rosterCount = $roster->count();

        $events = Event::query()
            ->where('starts_at', '>=', now()->subHours(3))
            ->orderBy('starts_at')
            ->with(['attendances.member.user:id,name'])
            ->get()
            ->map(function (Event $event) use ($member, $isManager, $roster, $rosterCount) {
                $byStatus = $event->attendances->groupBy('status');

                // Orden de inscripción: el primero que confirmó aparece primero
                // (lista de anotados). Empate/sin fecha: cae al final, estable.
                $names = fn ($status) => $byStatus->get($status, collect())
                    ->sortBy(fn ($a) => $a->responded_at?->timestamp ?? PHP_INT_MAX)
                    ->map(fn ($a) => [
                        'id' => $a->member_id,
                        'shirt_number' => $a->member->shirt_number,
                        'position' => $a->member->position,
                        'name' => $a->member->user->name,
                    ])->values();

                $going = $names('in');
                $maybe = $names('maybe');
                $out = $names('out');

                // No contestaron: jugadores activos sin fila de asistencia.
                // Ordenados por dorsal (el roster ya viene ordenado).
                $answered = $event->attendances->pluck('member_id')->all();
                $pending = $roster->reject(fn ($m) => in_array($m->id, $answered, true))
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'shirt_number' => $m->shirt_number,
                        'name' => $m->user->name,
                    ])->values();

                $data = [
                    'id' => $event->id,
                    'kind' => $event->kind,
                    'opponent' => $event->opponent,
                    'is_home' => $event->is_home,
                    'starts_at' => $event->starts_at->toIso8601String(),
                    'venue' => $event->venue,
                    'kit' => $event->kit,
                    'notes' => $event->notes,
                    'cancelled' => $event->isCancelled(),
                    'my_status' => $event->attendances->firstWhere('member_id', $member->id)?->status,
                    'counts' => [
                        'in' => $going->count(),
                        'maybe' => $maybe->count(),
                        'out' => $out->count(),
                        'pending' => $pending->count(),
                    ],
                    'going' => $going,
                    'maybe' => $maybe,
                    'out' => $out,
                    'pending' => $pending,
                    // Chances de ganar/empatar/perder (null si no es partido futuro)
                    'prediction' => app(PredictionService::class)->forEvent($event, $member),
                ];

                if ($isManager) {
                    // La lista copiable, una línea por puesto y por dorsal adentro,
                    // para armar el once de un vistazo. S/P = sin puesto cargado.
                    $data['convocation'] = collect(['ARQ', 'DEF', 'MED', 'DEL', null])
                        ->map(function ($position) use ($going) {
                            $line = $going->where('position', $position)
                                ->sortBy('shirt_number')
                                ->map(fn ($p) => trim($p['shirt_number'].' '.$p['name']))
                                ->implode(' · ');

                            return $line === '' ? null : ($position ?? 'S/P').': '.$line;
                        })
                        ->filter()
                        ->implode("\n");
                }

                return $data;
            });

        return Inertia::render('Agenda', [
            'events' => $events,
            'recent' => $this->recentMatches(),
            'roster_count' => $rosterCount,
            // Link firmado del club (rol jugador) para el mensaje de WhatsApp que
            // el manager pega en el grupo. Con la app: cae en la agenda; sin la
            // app: pantalla de descarga. Vence a los 7 días. Solo para el manager.
            'invite_url' => $isManager
                ? URL::temporarySignedRoute('invitacion', now()->addDays(7), [
                    'club' => $current->club()->slug,
                    'role' => 'player',
                ])
                : null,
            // Banner de cuota impaga en la home: desde el día 5 del mes y hasta
            // que pague, si el jugador debe la cuota de este mes (los del débito
            // automático no tienen due, así que no lo ven).
            'dues_banner' => now()->day >= 5 && Due::withoutGlobalScopes()
                ->where('club_id', $current->club()->id)
                ->where('member_id', $member->id)
                ->whereDate('period', now()->startOfMonth())
                ->where('status', 'pending')
                ->exists(),
            // Banner de legitimación: visible hasta que la ficha esté completa
            'legitimacion' => [
                'complete' => Registration::query()
                    ->where('member_id', $member->id)
                    ->where('season', config('legitimacion.season'))
                    ->whereIn('status', [Registration::STATUS_COMPLETO, Registration::STATUS_ENVIADO])
                    ->exists(),
                'daysLeft' => (int) now()->startOfDay()->diffInDays(config('legitimacion.deadline'), false),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $validated = $this->validated($request);

        $event = Event::create([
            ...$validated,
            'created_by_member_id' => app(CurrentClub::class)->member()->id,
        ]);

        // La convocatoria sale por WhatsApp a todo el plantel
        SendEventConvocation::dispatch($event);

        app(SystemMessages::class)->eventCreated($event);

        return redirect()->route('agenda');
    }

    /** Cambió la hora, la cancha, el rival: se avisa a todos por WhatsApp. */
    public function update(Request $request, Event $event): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($event);
        Gate::authorize('create', Event::class);
        abort_if($event->isCancelled(), 400);

        $event->update($this->validated($request));

        SendEventConvocation::dispatch($event, notice: 'update');
        app(SystemMessages::class)->eventUpdated($event);

        return redirect()->route('agenda');
    }

    public function cancel(Event $event): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($event);
        Gate::authorize('create', Event::class);

        if (! $event->isCancelled()) {
            $event->forceFill(['cancelled_at' => now()])->save();

            SendEventConvocation::dispatch($event, notice: 'cancel');
            app(SystemMessages::class)->eventCancelled($event);
        }

        return back();
    }

    /** El delegado carga el resultado cuando el partido terminó. */
    public function result(Request $request, Event $event): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($event);
        Gate::authorize('create', Event::class);
        abort_unless($event->isMatch() && $event->isFinished() && ! $event->isCancelled(), 400);

        $validated = $request->validate([
            'goals_for' => ['required', 'integer', 'min:0', 'max:99'],
            'goals_against' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $event->update($validated);

        app(SystemMessages::class)->result($event);

        return back();
    }

    public function remind(Event $event): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($event);
        Gate::authorize('create', Event::class);
        abort_if($event->isCancelled(), 400);

        SendEventConvocation::dispatch($event, onlyUnanswered: true);

        // La UI muestra "Recordatorio enviado a N": el job corre en cola, así que
        // el conteo se calcula acá con el mismo criterio que usa el job.
        return back()->with('reminded', $event->membersToRemind()->count());
    }

    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['match', 'training'])],
            'opponent' => ['required_if:kind,match', 'nullable', 'string', 'max:80'],
            'is_home' => ['required_if:kind,match', 'nullable', 'boolean'],
            'starts_at' => ['required', 'date', 'after:now'],
            'venue' => ['required', 'string', 'max:120'],
            // La casaca es cosa de partidos ("both" = llevar las dos por las dudas);
            // en los entrenamientos no se aclara
            'kit' => ['required_if:kind,match', 'nullable', Rule::in(['home', 'away', 'both'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return [
            ...$validated,
            'opponent' => $validated['kind'] === 'match' ? $validated['opponent'] : null,
            'is_home' => (bool) ($validated['is_home'] ?? true),
            'kit' => $validated['kit'] ?? 'home',
        ];
    }

    /** Los últimos 3 partidos jugados, con resultado y figura si ya cerró. */
    protected function recentMatches(): array
    {
        $current = app(CurrentClub::class);
        $isManager = $current->actsAsManager();
        // Para confirmar presentes: el plantel activo, una sola vez
        $roster = $isManager
            ? $current->club()->activeMembers()->with('user:id,name')->orderBy('shirt_number')->get()
            : null;

        return Event::query()
            ->where('kind', 'match')
            ->whereNull('cancelled_at')
            ->where('starts_at', '<', now()->subHours(2))
            ->orderByDesc('starts_at')
            ->limit(3)
            ->with(['mvpVotes.voted.user:id,name', 'attendances'])
            ->get()
            ->map(function (Event $event) use ($isManager, $roster) {
                $winner = null;

                if ($event->mvp_closed_at && $event->mvpVotes->isNotEmpty()) {
                    $top = $event->mvpVotes->countBy('voted_member_id')->sortDesc();
                    $winner = $event->mvpVotes
                        ->firstWhere('voted_member_id', $top->keys()->first())
                        ?->voted?->user?->name;
                }

                $data = [
                    'id' => $event->id,
                    'opponent' => $event->opponent,
                    'is_home' => $event->is_home,
                    'starts_at' => $event->starts_at->toIso8601String(),
                    'result' => $event->hasResult()
                        ? ['gf' => $event->goals_for, 'ga' => $event->goals_against]
                        : null,
                    'mvp' => $winner,
                ];

                if ($isManager) {
                    $data['presence'] = [
                        'confirmed' => $event->attendance_confirmed_at !== null,
                        'players' => $roster->map(fn ($m) => [
                            'id' => $m->id,
                            'name' => $m->user->name,
                            'shirt_number' => $m->shirt_number,
                            'present' => $event->attendance_confirmed_at
                                ? (bool) $event->attendances->firstWhere('member_id', $m->id)?->attended
                                : $event->attendances->firstWhere('member_id', $m->id)?->status === 'in',
                        ])->values(),
                    ];
                }

                return $data;
            })
            ->all();
    }
}
