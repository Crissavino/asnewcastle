<?php

namespace App\Http\Middleware;

use App\Support\CurrentClub;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el club activo del usuario logueado. Un usuario puede ser
 * member de varios clubes; el activo se guarda en sesión y siempre se
 * valida contra sus membresías reales — nunca se confía en un id que
 * venga del cliente.
 */
class SetActiveClub
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $memberships = $user->activeMembers()->with('club')->get();

        if ($memberships->isEmpty()) {
            return redirect()->route('sin-club');
        }

        $member = $memberships->firstWhere('club_id', $request->session()->get('active_club_id'))
            ?? $memberships->first();

        $request->session()->put('active_club_id', $member->club_id);

        app(CurrentClub::class)->set($member->club, $member);

        return $next($request);
    }
}
