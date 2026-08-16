<?php

namespace App\Http\Controllers;

use App\Jobs\SendEventConvocation;
use App\Models\Event;
use App\Services\SystemMessages;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AgendaController extends Controller
{
    public function index(): Response
    {
        $current = app(CurrentClub::class);
        $member = $current->member();
        $isManager = $member->isManager();

        $rosterCount = $current->club()->activeMembers()->count();

        $events = Event::query()
            ->where('starts_at', '>=', now()->subHours(3))
            ->orderBy('starts_at')
            ->with(['attendances.member.user:id,name'])
            ->get()
            ->map(function (Event $event) use ($member, $isManager, $rosterCount) {
                $byStatus = $event->attendances->groupBy('status');

                $names = fn ($status) => $byStatus->get($status, collect())
                    ->sortBy(fn ($a) => $a->member->shirt_number)
                    ->map(fn ($a) => [
                        'id' => $a->member_id,
                        'shirt_number' => $a->member->shirt_number,
                        'name' => $a->member->user->name,
                    ])->values();

                $going = $names('in');
                $maybe = $names('maybe');
                $out = $names('out');
                $pending = $rosterCount - $event->attendances->count();

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
                        'pending' => max($pending, 0),
                    ],
                    'going' => $going,
                    'maybe' => $maybe,
                    'out' => $out,
                ];

                if ($isManager) {
                    // La lista en texto plano copiable: "10 Cristian Savino · 5 Sergio Quiroga"
                    $data['convocation'] = $going
                        ->map(fn ($p) => trim($p['shirt_number'].' '.$p['name']))
                        ->implode(' · ');
                }

                return $data;
            });

        return Inertia::render('Agenda', [
            'events' => $events,
            'recent' => $this->recentMatches(),
            'roster_count' => $rosterCount,
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

        return back();
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
        return Event::query()
            ->where('kind', 'match')
            ->whereNull('cancelled_at')
            ->where('starts_at', '<', now()->subHours(2))
            ->orderByDesc('starts_at')
            ->limit(3)
            ->with(['mvpVotes.voted.user:id,name'])
            ->get()
            ->map(function (Event $event) {
                $winner = null;

                if ($event->mvp_closed_at && $event->mvpVotes->isNotEmpty()) {
                    $top = $event->mvpVotes->countBy('voted_member_id')->sortDesc();
                    $winner = $event->mvpVotes
                        ->firstWhere('voted_member_id', $top->keys()->first())
                        ?->voted?->user?->name;
                }

                return [
                    'id' => $event->id,
                    'opponent' => $event->opponent,
                    'is_home' => $event->is_home,
                    'starts_at' => $event->starts_at->toIso8601String(),
                    'result' => $event->hasResult()
                        ? ['gf' => $event->goals_for, 'ga' => $event->goals_against]
                        : null,
                    'mvp' => $winner,
                ];
            })
            ->all();
    }
}
