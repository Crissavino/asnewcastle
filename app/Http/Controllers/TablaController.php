<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Member;
use App\Support\CurrentClub;
use Inertia\Inertia;
use Inertia\Response;

class TablaController extends Controller
{
    public function show(): Response
    {
        $club = app(CurrentClub::class)->club();
        $standings = $club->standings_json;

        // Nuestra fila del clasament, si está
        $us = collect($standings ?? [])->firstWhere('us', true);

        // Racha: los últimos 5 partidos con resultado cargado
        $form = Event::query()
            ->where('kind', 'match')
            ->whereNull('cancelled_at')
            ->whereNotNull('goals_for')
            ->orderByDesc('starts_at')
            ->limit(5)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Event $e) => [
                'id' => $e->id,
                'outcome' => $e->goals_for <=> $e->goals_against, // 1 V · 0 E · -1 D
                'label' => "{$e->goals_for}–{$e->goals_against} vs {$e->opponent}",
            ]);

        // El que viene
        $next = Event::query()
            ->where('kind', 'match')
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        return Inertia::render('Tabla', [
            'ranking' => $this->ranking(),
            'standings' => $standings,
            'fixture' => $club->fixture_json,
            'us' => $us,
            'form' => $form,
            'next' => $next ? [
                'opponent' => $next->opponent,
                'is_home' => $next->is_home,
                'starts_at' => $next->starts_at->toIso8601String(),
                'venue' => $next->venue,
            ] : null,
        ]);
    }

    /**
     * Rankings del plantel, visibles para todos. Solo métricas celebrables:
     * goles, asistencias, figuras y presencia. Las calificaciones del
     * vestuario y los faltazos siguen siendo privados: esto celebra, no escracha.
     */
    protected function ranking(): array
    {
        $players = app(CurrentClub::class)->club()->activeMembers()
            ->with('user:id,name')
            ->get();

        $matches = Event::query()
            ->where('kind', 'match')
            ->whereNull('cancelled_at')
            ->where('starts_at', '<', now())
            ->with(['attendances', 'mvpVotes'])
            ->get();

        $mine = fn (Event $e, Member $m) => $e->attendances->firstWhere('member_id', $m->id);

        // Figuras: mismo criterio que StatsService — el más votado (empates comparten)
        $mvpWinners = $matches
            ->filter(fn (Event $e) => $e->mvp_closed_at && $e->mvpVotes->isNotEmpty())
            ->flatMap(function (Event $e) {
                $counts = $e->mvpVotes->countBy('voted_member_id');

                return $counts->filter(fn ($n) => $n === $counts->max())->keys();
            })
            ->countBy();

        $top = fn (callable $value) => $players
            ->map(fn (Member $m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'shirt_number' => $m->shirt_number,
                'value' => $value($m),
            ])
            ->filter(fn ($row) => $row['value'] !== null && $row['value'] > 0)
            ->sortByDesc('value')
            ->take(5)
            ->values()
            ->all();

        return [
            'goals' => $top(fn (Member $m) => $matches->sum(fn (Event $e) => $mine($e, $m)?->goals ?? 0)),
            'assists' => $top(fn (Member $m) => $matches->sum(fn (Event $e) => $mine($e, $m)?->assists ?? 0)),
            'mvps' => $top(fn (Member $m) => $mvpWinners->get($m->id, 0)),
            // Presencia: % de partidos jugados desde que se sumó; con menos de
            // 3 partidos posibles no rankea (1 de 1 no es 100% de nada)
            'attendance' => $top(function (Member $m) use ($matches) {
                $eligible = $matches->filter(fn (Event $e) => ! $m->joined_at || $e->starts_at >= $m->joined_at);

                if ($eligible->count() < 3) {
                    return null;
                }

                $played = $eligible->filter(fn (Event $e) => $e->wasPresent($m->id))->count();

                return (int) round($played / $eligible->count() * 100);
            }),
        ];
    }
}
