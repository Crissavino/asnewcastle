<?php

namespace App\Http\Controllers;

use App\Models\Event;
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
}
