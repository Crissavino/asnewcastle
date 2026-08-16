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
                $outCount = $byStatus->get('out', collect())->count();
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
                    'my_status' => $event->attendances->firstWhere('member_id', $member->id)?->status,
                    'counts' => [
                        'in' => $going->count(),
                        'maybe' => $maybe->count(),
                        'out' => $outCount,
                        'pending' => max($pending, 0),
                    ],
                    'going' => $going,
                    'maybe' => $maybe,
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
            'roster_count' => $rosterCount,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Event::class);

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

        $event = Event::create([
            ...$validated,
            'opponent' => $validated['kind'] === 'match' ? $validated['opponent'] : null,
            'is_home' => (bool) ($validated['is_home'] ?? true),
            'kit' => $validated['kit'] ?? 'home',
            'created_by_member_id' => app(CurrentClub::class)->member()->id,
        ]);

        // La convocatoria sale por WhatsApp a todo el plantel
        SendEventConvocation::dispatch($event);

        app(SystemMessages::class)->eventCreated($event);

        return redirect()->route('agenda');
    }

    public function remind(Event $event): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($event);

        Gate::authorize('create', Event::class);

        SendEventConvocation::dispatch($event, onlyUnanswered: true);

        return back();
    }
}
